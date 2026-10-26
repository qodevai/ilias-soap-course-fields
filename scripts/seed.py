"""Seed the scratch ILIAS: 4 users, 1 course, 3 field definitions, 2 members. Writes .seed.json."""
import json
import secrets
import subprocess
import sys

sys.path.insert(0, "scripts")
from soap import client, login

PASSWORD = "Aa1!" + secrets.token_urlsafe(12)  # random per run; e2e.py reads it from .seed.json
USERS = {  # login -> role in course (None = no membership)
    "cadmin": "Admin",    # course admin: has manage_members
    "member1": "Member",
    "member2": "Member",
    "outsider": None,     # not in the course
}


def sql(q: str) -> str:
    r = subprocess.run(
        ["docker", "exec", "ilias-soap-spike-db-1", "mariadb", "-uilias", "-pilias", "ilias", "-N", "-B", "-e", q],
        capture_output=True, text=True, check=True)
    return r.stdout.strip()


def php_list(items: list[str]) -> str:
    body = "".join(f'i:{i};s:{len(s.encode())}:"{s}";' for i, s in enumerate(items))
    return f"a:{len(items)}:{{{body}}}"


def php_array_int(items: list[int]) -> str:
    return f"a:{len(items)}:{{" + "".join(f"i:{n};i:{v};" for n, v in enumerate(items)) + "}"


c = client()
sid = login(c, "root", "homer")

users_xml = "<Users>" + "".join(
    f'<User Language="en" Action="Insert"><Login>{u}</Login><Password Type="PLAIN">{PASSWORD}</Password>'
    f'<Gender>m</Gender><Firstname>{u}</Firstname><Lastname>Spike</Lastname><Email>{u}@example.test</Email>'
    f'<Role Id="il_0_role_4" Type="Global" Action="Assign">User</Role></User>' for u in USERS) + "</Users>"
print("importUsers:", c.service.importUsers(sid=sid, folder_id=7, usr_xml=users_xml, conflict_rule=2, send_account_mail=0)[:300])

course_xml = (
    '<Course><MetaData><General Structure="Hierarchical"><Identifier Catalog="ILIAS" Entry=""/>'
    '<Title>CUF spike course</Title><Language Language="en"/><Description>course user fields spike</Description>'
    '<Keyword Language="en"/></General></MetaData>'
    '<Settings><Availability><Unlimited/></Availability><Syllabus/><ImportantInformation/><Target/>'
    '<Registration registrationType="3" maxMembers="0" notification="No" waitingList="No"><Unlimited/></Registration>'
    '</Settings></Course>')
existing = sql("SELECT r.ref_id, r.obj_id FROM object_data d JOIN object_reference r USING (obj_id) "
               "WHERE d.type='crs' AND d.title='CUF spike course' AND r.deleted IS NULL")
if existing:
    ref_id, obj_id = map(int, existing.split())
else:
    ref_id = int(c.service.addCourse(sid=sid, target_id=1, crs_xml=course_xml))
    obj_id = int(sql(f"SELECT obj_id FROM object_reference WHERE ref_id={ref_id}"))
sql(f"DELETE FROM crs_f_definitions WHERE obj_id={obj_id}")
print("course ref", ref_id, "obj", obj_id)

user_ids = {}
for login_name, role in USERS.items():
    uid = int(sql(f"SELECT usr_id FROM usr_data WHERE login='{login_name}'"))
    user_ids[login_name] = uid
    if role:
        print("assign", login_name, c.service.assignCourseMember(sid=sid, course_id=ref_id, user_id=uid, type=role))

fields = [  # name, type (1 text, 2 select), options, open-answer option indexes, required
    ("Notiz", 1, [], [], 0),
    ("Allergie", 2, ["keine", "Nuesse", "Sonstiges"], [2], 1),
    ("Kursraum", 2, ["A1", "B2"], [], 0),
]
field_ids = {}
for name, ftype, opts, open_idx, req in fields:
    fid = int(sql("INSERT INTO crs_f_definitions_seq VALUES (); SELECT LAST_INSERT_ID()"))
    opt_idx = php_array_int(open_idx)
    sql(f"INSERT INTO crs_f_definitions (field_id,obj_id,field_name,field_type,field_values,field_required,field_values_opt) "
        f"VALUES ({fid},{obj_id},'{name}',{ftype},'{php_list(opts)}',{req},'{opt_idx}')")
    field_ids[name] = fid

out = dict(ref_id=ref_id, obj_id=obj_id, users=user_ids, fields=field_ids, password=PASSWORD)
json.dump(out, open(".seed.json", "w"), indent=2)
print({k: v for k, v in out.items() if k != "password"})  # the password stays in .seed.json
