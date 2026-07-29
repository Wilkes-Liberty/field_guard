# Security policy

## Reporting a vulnerability

Field Guard is covered by the Drupal Security Team's advisory policy once it has a
stable release. Report suspected vulnerabilities through the Drupal Security Team
rather than the public issue queue:

<https://www.drupal.org/security-team/report-issue>

Do not open a public issue for a suspected vulnerability.

For anything that is not a vulnerability, the public queue is the right place:
<https://www.drupal.org/project/issues/field_guard>

## Scope

Field Guard is an access-control module, so a report that it **grants** access it
should have denied is in scope and treated as high severity. In particular:

- A guarded field readable or writable by an account holding no explicitly granted
  permission.
- A guarded field reachable through a path that does not consult
  `hook_entity_field_access()` **where that path could reasonably be expected to** —
  render, form, JSON:API, GraphQL, Views output.
- A guarded field filterable or sortable through JSON:API or Views.

## Known limitations, deliberately out of scope

These are documented in the README and are properties of Drupal rather than defects
in this module. Reports about them are welcome as issues, but they are not
vulnerabilities in Field Guard:

- **Programmatic access.** `$entity->get('field')->value` and Drush do not call
  `fieldAccess()` and never have. Any module implementing
  `hook_entity_field_access()` shares this boundary.
- **Direct database access.** Anything below the entity API sees everything.
- **Views filters and sorts on fields that are not guarded.**
  `HandlerBase::access()` returns TRUE unconditionally.
- **Absence of audit logging.** Drupal does not log field reads.

If you believe one of these can be closed rather than merely documented, that is a
feature discussion and a genuinely welcome one.
