"""End-to-end checks of the CourseUserFields SOAP plugin against the scratch ILIAS (run scripts/seed.py first)."""
import json
import subprocess
import sys
from xml.sax.saxutils import escape

from zeep.exceptions import Fault

sys.path.insert(0, "scripts")
from soap import client, login

S = json.load(open(".seed.json"))
REF, PW = S["ref_id"], S["password"]
F = S["fields"]
U = S["users"]
c = client()
failures = []


def db(q: str) -> str:
    return subprocess.run(
        ["docker", "exec", "ilias-soap-spike-db-1", "mariadb", "-uilias", "-pilias", "ilias", "-N", "-B", "-e", q],
        capture_output=True, text=True, check=True).stdout.strip()


def db_root(q: str) -> str:
    return subprocess.run(
        ["docker", "exec", "ilias-soap-spike-db-1", "mariadb", "-uroot", "-proot", "ilias", "-N", "-B", "-e", q],
        capture_output=True, text=True, check=True).stdout.strip()


def stored(uid: int) -> dict[int, str]:
    rows = db(f"SELECT field_id, value FROM crs_user_data WHERE usr_id={uid} ORDER BY field_id")
    return {int(a): b for a, b in (r.split("\t", 1) for r in rows.splitlines())}


def check(name: str, ok: bool, detail: str = "") -> None:
    print(("PASS " if ok else "FAIL ") + name + (f"  [{detail}]" if detail and not ok else ""))
    if not ok:
        failures.append(name)


def set_values(sid, uid, pairs: dict[int, str], raw: str | None = None):
    xml = raw if raw is not None else "<CourseUserFieldValues>" + "".join(
        f'<Value field_id="{k}">{escape(v)}</Value>' for k, v in pairs.items()) + "</CourseUserFieldValues>"
    return c.service.setCourseUserFieldValues(sid=sid, ref_id=REF, user_id=uid, xml=xml)


def expect_fault(name: str, fn, needle: str) -> None:
    try:
        r = fn()
        check(name, False, f"no fault, got {r!r}")
    except Fault as e:
        check(name, needle.lower() in str(e).lower(), str(e))


admin = login(c, "cadmin", PW)
m1 = login(c, "member1", PW)
out = login(c, "outsider", PW)
root = login(c, "root", "homer")

# --- definitions
defs = c.service.getCourseUserFields(sid=admin, ref_id=REF)
check("defs list all 3 fields", all(f'id="{i}"' in defs for i in F.values()), defs[:200])
check("defs show select option", "<Option" in defs and "Nuesse" in defs)
check("defs mark open-answer option", 'open="1"' in defs)
expect_fault("defs: member without manage_members denied", lambda: c.service.getCourseUserFields(sid=m1, ref_id=REF), "permission")
expect_fault("defs: non-course ref_id rejected", lambda: c.service.getCourseUserFields(sid=admin, ref_id=1), "not a course")

# --- write happy path
r = set_values(admin, U["member1"], {F["Notiz"]: "Hallo & Welt", F["Allergie"]: "keine", F["Kursraum"]: "A1"})
check("set returns 1", int(r) == 1, str(r))
s = stored(U["member1"])
check("stored select as option TEXT", s.get(F["Allergie"]) == "keine" and s.get(F["Kursraum"]) == "A1", str(s))
check("stored text decoded", s.get(F["Notiz"]) == "Hallo & Welt", str(s))

hist = db(f"SELECT update_user FROM obj_user_data_hist WHERE obj_id={S['obj_id']} AND usr_id={U['member1']}")
check("set records edit history (who changed it)", hist == str(U["cadmin"]), hist)

# open answer + overwrite (update, not duplicate)
set_values(admin, U["member1"], {F["Allergie"]: "Erdbeeren"})
s = stored(U["member1"])
check("open-answer free text accepted", s.get(F["Allergie"]) == "Erdbeeren", str(s))
check("partial write keeps other fields", s.get(F["Kursraum"]) == "A1" and len(s) == 3, str(s))

# --- write rejections (nothing may be written)
before = stored(U["member2"])
expect_fault("set: member lacks manage_members", lambda: set_values(m1, U["member2"], {F["Notiz"]: "x"}), "permission")
expect_fault("set: outsider lacks manage_members", lambda: set_values(out, U["member2"], {F["Notiz"]: "x"}), "permission")
expect_fault("set: target not a course member", lambda: set_values(admin, U["outsider"], {F["Notiz"]: "x"}), "not a member")
expect_fault("set: invalid select value", lambda: set_values(admin, U["member2"], {F["Notiz"]: "ok", F["Kursraum"]: "Z9"}), "Kursraum")
expect_fault("set: required field empty", lambda: set_values(admin, U["member2"], {F["Notiz"]: "ok", F["Allergie"]: ""}), "required")
expect_fault("set: unknown field id", lambda: set_values(admin, U["member2"], {99999: "x"}), "does not belong")
expect_fault("set: duplicate field id", lambda: set_values(admin, U["member2"], {}, raw=
             f'<CourseUserFieldValues><Value field_id="{F["Notiz"]}">a</Value><Value field_id="{F["Notiz"]}">b</Value></CourseUserFieldValues>'), "duplicate")
expect_fault("set: malformed xml", lambda: set_values(admin, U["member2"], {}, raw="<CourseUserFieldValues>"), "xml")
expect_fault("set: DOCTYPE/entities rejected", lambda: set_values(admin, U["member2"], {}, raw=
             '<!DOCTYPE x [<!ENTITY e "boom">]><CourseUserFieldValues><Value field_id="%d">&e;</Value></CourseUserFieldValues>' % F["Notiz"]), "xml")
expect_fault("set: value over 4000 characters", lambda: set_values(admin, U["member2"], {F["Notiz"]: "x" * 4001}), "longer than")
check("rejections wrote nothing", stored(U["member2"]) == before, str(stored(U["member2"])))
expect_fault("set: HTML in a value", lambda: set_values(admin, U["member2"], {F["Notiz"]: "<img src=x onerror=alert(1)>"}), "HTML")
expect_fault("set: invalid sid", lambda: set_values("nope", U["member2"], {F["Notiz"]: "x"}), "")

# --- db error in the middle of a write: the call changes nothing. A trigger rejects one value to fake the error.
before = stored(U["member1"])
db_root("DROP TRIGGER IF EXISTS cuf_fail")
db_root("DELIMITER //\nCREATE TRIGGER cuf_fail BEFORE INSERT ON crs_user_data FOR EACH ROW BEGIN "
        "IF NEW.value = '__fail__' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced failure'; END IF; END//")
try:
    expect_fault("set: db error restores the previous values",
                 lambda: set_values(admin, U["member1"], {F["Allergie"]: "Nüsse", F["Notiz"]: "__fail__"}), "restored")
    try:
        set_values(admin, U["member1"], {F["Notiz"]: "__fail__"})
    except Fault as e:
        check("set: db error text is not sent to the caller", "forced failure" not in str(e) and "SQLSTATE" not in str(e), str(e))
    check("set: db error left all values as they were", stored(U["member1"]) == before, str(stored(U["member1"])))
finally:
    db_root("DROP TRIGGER IF EXISTS cuf_fail")

# --- an unexpected error in a read method reaches the caller as a plain fault without SQL text
db_root("RENAME TABLE crs_f_definitions TO crs_f_definitions_x")
try:
    try:
        c.service.getCourseUserFields(sid=admin, ref_id=REF)
        check("defs: unexpected error gives a generic fault", False, "no fault")
    except Fault as e:
        check("defs: unexpected error gives a generic fault", "Internal error" in str(e) and "SQLSTATE" not in str(e), str(e))
finally:
    db_root("RENAME TABLE crs_f_definitions_x TO crs_f_definitions")

# --- read: gate is ilPrivacySettings::checkExportAccess, same as the members export in the UI
db("DELETE FROM settings WHERE keyword='ps_export_course'")
expect_fault("values: export disabled -> even system admin denied", lambda: c.service.getCourseUserFieldValues(sid=root, ref_id=REF), "permission")
db("INSERT INTO settings (module, keyword, value) VALUES ('common', 'ps_export_course', '1')")  # Administration > Privacy > Export Course Member Data
expect_fault("values: export on, course admin lacks export_member_data -> denied", lambda: c.service.getCourseUserFieldValues(sid=admin, ref_id=REF), "permission")
expect_fault("values: plain member denied", lambda: c.service.getCourseUserFieldValues(sid=m1, ref_id=REF), "permission")
db(f"DELETE FROM crs_user_data WHERE usr_id={U['outsider']} AND field_id={F['Notiz']}")
db(f"INSERT INTO crs_user_data (usr_id, field_id, value) VALUES ({U['outsider']}, {F['Notiz']}, 'outsider-secret')")
vals = c.service.getCourseUserFieldValues(sid=root, ref_id=REF)
check("values: system admin sees member1 data", 'usr_id="%d"' % U["member1"] in vals and "Erdbeeren" in vals, vals[:300])
check("values: outsider data not exported", 'usr_id="%d"' % U["outsider"] not in vals and "outsider-secret" not in vals)
db(f"DELETE FROM crs_user_data WHERE usr_id={U['outsider']} AND field_id={F['Notiz']}")
print(vals)

# course confirmation on: members who have not accepted the agreement are hidden, like in the participants table
db("DELETE FROM settings WHERE keyword='ps_export_confirm'")
db("INSERT INTO settings (module, keyword, value) VALUES ('common', 'ps_export_confirm', '1')")
vals = c.service.getCourseUserFieldValues(sid=root, ref_id=REF)
check("values: confirmation required, member1 not accepted -> hidden", 'usr_id="%d"' % U["member1"] not in vals, vals[:300])
db(f"DELETE FROM member_agreement WHERE usr_id={U['member1']} AND obj_id={S['obj_id']}")
db(f"INSERT INTO member_agreement (usr_id, obj_id, accepted, acceptance_time) VALUES ({U['member1']}, {S['obj_id']}, 1, UNIX_TIMESTAMP())")
vals = c.service.getCourseUserFieldValues(sid=root, ref_id=REF)
check("values: confirmation required, member1 accepted -> visible", 'usr_id="%d"' % U["member1"] in vals, vals[:300])
db(f"DELETE FROM member_agreement WHERE usr_id={U['member1']} AND obj_id={S['obj_id']}")
db("DELETE FROM settings WHERE keyword='ps_export_confirm'")

print("\n%d failure(s)" % len(failures))
sys.exit(1 if failures else 0)
