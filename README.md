# CourseUserFields

SOAP plugin for ILIAS 10 that reads and writes course-specific user fields (the fields of the course
agreement, "Kursvereinbarung"). Requires ILIAS 10 (tested on 10.11) and PHP 8.2 or newer.

ILIAS has no SOAP access to these fields: `getCourseXML`, `addCourse` and `updateCourse` carry no field
values. This plugin adds three SOAP methods through the `SoapHook` plugin slot.

## Methods (namespace `urn:CourseUserFields`)

| Method | Needs | Returns |
|---|---|---|
| `getCourseUserFields(sid, ref_id)` | `manage_members` on the course | field definitions |
| `getCourseUserFieldValues(sid, ref_id)` | the UI member-export gate (`ilPrivacySettings::checkExportAccess`) | values of all course participants |
| `setCourseUserFieldValues(sid, ref_id, user_id, xml)` | `manage_members`; user must be a course member | `1` |

Permissions are checked for the user of the SOAP session, not for the system.

```xml
<!-- getCourseUserFields -->
<CourseUserFields ref_id="86" obj_id="320">
  <Field id="4" name="Allergie" type="select" required="1">
    <Option open="0">keine</Option><Option open="1">Sonstiges</Option>
  </Field>
</CourseUserFields>

<!-- setCourseUserFieldValues (xml parameter); getCourseUserFieldValues returns the same Value elements per <Member usr_id=".."> -->
<CourseUserFieldValues><Value field_id="4">keine</Value></CourseUserFieldValues>
```

Rules for `set`:
- Values are stored like the UI stores them: the option **text** for select fields.
- A select value that is not an option text is rejected, unless the field has an open-answer option.
- A required field must not be empty.
- Unknown or duplicate `field_id`, malformed XML and DOCTYPE/entities are rejected.
- A value is at most 4000 characters (`crs_user_data.value` is `varchar(4000)`).
- A value must not contain HTML tags that ILIAS strips from the web form (`ilUtil::stripSlashes()`), because
  the participants table prints stored values without escaping. Allowed tags such as `<b>` pass.
- All values are validated before the first write. If a database error occurs while writing, the previous
  values are put back and the call returns a fault. ILIAS has no database transaction here, so this is a
  best effort: the fault names any field that could not be restored. Two simultaneous `set` calls for the
  same user can overwrite each other, as two simultaneous edits in the web form can.
- Fields not mentioned in the XML stay unchanged. Unlike the UI form, `set` does not require the
  required fields to be present.
- `set` writes the same edit-history row as the admin edit form, so the participants table shows the
  SOAP user and time in the "Updated (Course-specific Data)" column.

`getCourseUserFieldValues` follows the participants table. It lists only members that have stored values.
If the privacy setting "course confirmation" is on, only members who accepted the agreement are returned.
Course export must be enabled (Administration > Privacy and Security, setting `ps_export_course`), and the
caller needs `export_member_data` on the privacy settings object. Course admins usually do not have it.

## Calling the methods

The methods are part of the ILIAS SOAP service, so SOAP must be enabled for the client. Log in first to get
the session id `sid`:

```python
from zeep import Client

c = Client("https://ilias.example.org/soap/server.php?client_id=CLIENT&wsdl")
sid = c.service.login(client="CLIENT", username="user", password="secret")
print(c.service.getCourseUserFields(sid=sid, ref_id=86))
```

## Member agreement

`set` writes the values only. It does not set `member_agreement.accepted`, because that records consent
by the user. A course member who is not a course admin still sees the agreement form
(`ilMemberAgreementGUI`) until they accept it, or while a required field is empty. The form shows the
stored values pre-filled.

## Install

Run the commands in the ILIAS installation root, as the web server user.

**Order matters.** Run steps 1 and 2 *before* you activate the plugin. If the plugin is active but its class is
not in the composer classmap, every ILIAS page (not only SOAP) redirects to `error.php`. To recover, set
`active = 0` for `cufsoap` in table `il_plugin`.

1. Copy `plugin/` to `public/Customizing/global/plugins/Services/WebServices/SoapHook/CourseUserFields/`.
2. `composer dump-autoload` and `php cli/setup.php build` (ILIAS 10 finds plugins through build artifacts), then `php cli/setup.php update`.
3. Administration > Extending ILIAS > Plugins: install and activate `CourseUserFields`.

## Development

```
composer install && docker run --rm -v "$PWD:/w" -w /w php:8.3-cli php vendor/bin/phpunit   # unit tests
docker compose -f docker/compose.yml up -d --build      # own ILIAS 10.11 on 127.0.0.1:8081 (first boot: minutes)
./scripts/install-plugin.sh                              # copy, build, composer dump-autoload, activate
uv run --with zeep python scripts/seed.py                # users, course, 3 fields
uv run --with zeep python scripts/e2e.py                 # 35 checks
```

The compose file builds its own ILIAS 10.11 image from `docker/ilias`, with its own project name and volumes.
`docker compose -f docker/compose.yml down -v` removes the stack.

Code style (PSR-12) and unit tests also run in CI:

```
vendor/bin/php-cs-fixer fix --dry-run --diff
vendor/bin/phpunit
```

## License

GPL-3.0-only. See `LICENSE`.
