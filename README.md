# Field Guard

Config-driven per-field access control that **fails closed**.

Name a field in configuration and Field Guard denies it to anyone who has not been
*explicitly* granted the permission you nominate — including administrators and user 1.

It defines no permissions of its own. Point it at whatever permission your site or another
module already provides; Field Guard only ever checks whether that permission has been
explicitly granted, and it only ever denies. It never grants.

## Why you might want it

Two problems, both of which are easy to get wrong by hand.

### 1. Definition-level checks fail open

JSON:API and Views ask a different question from "may this user read this value?" They ask
"may this field be **filtered or sorted on** at all?" — and they ask it with no entity in
scope:

- `Drupal\views\Plugin\views\field\EntityField::access()` calls
  `fieldAccess('view', $definition, $account)`; `$items` is omitted, so it is NULL.
- `Drupal\jsonapi\Context\FieldResolver::getFieldAccess()` calls
  `fieldAccess('view', $definition, NULL, NULL, TRUE)`; `$items` is explicitly NULL.

An implementation that returns `neutral()` for that case leaves the Views handler in place
and permits the JSON:API filter. The value is never rendered, but it can be **probed** — an
exposed date filter binary-searches it, and a sort orders by it.

Field Guard denies. If a caller will not say which entity it means, it does not get the
field.

Note the consequence: guarded fields become unfilterable and unsortable **for everyone**,
including permission holders. With no entity in scope there is no way to distinguish a
legitimate filter from a probe. For a field you deliberately guarded, that is usually the
behaviour you want — but it is a behaviour change, and it is pinned by a test rather than
left to chance.

### 2. Checking `hasPermission()` silently exempts your administrators

This is the subtle one.

`hook_entity_field_access()` returning `AccessResult::forbidden()` genuinely cannot be
overridden. `EntityAccessControlHandler::fieldAccess()` contains no admin check at all,
folds hook results with `orIf()`, and forbidden is contagious — so the verdict holds against
`administer users`, an `is_admin` role, and user 1.

**But that only matters once you have decided to forbid.** If your deny condition is
`!$account->hasPermission($permission)`, administrators and user 1 pass it every time,
because `SuperUserAccessPolicy` and `is_admin` roles grant *every permission by definition*.
You never reach the forbidden branch, and the contagion never fires.

Field Guard therefore walks the account's roles, **skips `is_admin` roles**, and asks each
remaining role directly. Access must be named in a role's own configuration.

The practical effect is separation of duty: granting access is a visible line in a
configuration diff, reviewed like any other change, rather than something inherited by being
an administrator.

## Configuration

```yaml
# field_guard.settings.yml
protected:
  profile:
    compliance_record:
      field_evidence_date:
        view: 'view compliance evidence'
        edit: 'record compliance evidence'
```

`<entity_type>` → `<bundle>` → `<field_name>` → `<operation>` → permission.

- `view` and `edit` are the only operations Drupal's field access API passes. Anything else
  is treated as unprotected, so a future core operation cannot lock a site out of its own
  data.
- An omitted operation is **not** protected. Omission is how you leave a field open.
- An empty permission string is treated as unset, not as a permission nobody holds — a total
  denial that is invisible in config is very hard to diagnose.
- Ships empty. Installing the module changes nothing until you configure it.

Configuration rather than a hardcoded array is deliberate: it is diffable and reviewable, and
on a deploy that runs `drush config:import` it is reverted to the repository on every
release, so a live edit that widens access does not survive.

### Letting the record's subject read their own record

```yaml
protected:
  paragraph:
    client_document:
      field_client_file:
        view: 'manage client documents'
        view_exempt_own_subject: true
```

Some guarded fields are records *about* a user, stored on that user's account — directly, or
on a composition entity (a paragraph, an inline entity) hanging from it. `view_exempt_own_subject: true`
makes the **view** guard stand aside when the entity carrying the field ultimately roots at
the acting user's own account. The module still never grants: it returns neutral and ordinary
entity and field access decide.

The host chain is resolved by duck-typing on `getParentEntity()` (Paragraphs and friends),
depth-capped and cycle-guarded, and it fails closed — an unresolvable chain, an orphan, or a
root that is not the acting user exempts nothing. Anonymous is never a subject.

Scope is deliberate, and narrow:

- **View only.** There is no edit counterpart and there never will be: a record its subject
  can rewrite is not evidence. The edit guard on the same field is untouched.
- **The definition-level deny is untouched.** Without an entity there is no subject, so
  filter/sort probing stays closed for everyone, subject included.
- **Opt-in per field.** Only a boolean `true` enables it; any other value fails closed.
- **Everyone else is still denied**, administrators and uid 1 included, exactly as before.
  Named permission holders still pass.

## What it does not cover

Be clear-eyed about the boundary. These are properties of Drupal, not gaps in this module,
and they are true of every field-access approach:

- **Programmatic reads.** `$entity->get('field')->value` and Drush never call `fieldAccess()`.
- **Views filters, sorts and arguments on unguarded fields.** `HandlerBase::access()` returns
  `TRUE` unconditionally; guarding at definition level is the only lever available.
- **Anything below the entity API.** Direct SQL sees everything.
- **Audit.** Drupal does not log field reads. `ProtectedFieldMap::isProtected()` is provided
  as the seam for a consumer that wants to record them.

### Do not guard workflow fields on `edit`

Never map `moderation_state`, `status`, or any field whose legal values depend on the
value being *replaced*, on the `edit` operation. During a JSON:API/REST PATCH, core
checks field edit-access against the **stored** value
(`EntityResource::checkPatchFieldAccess()`), not the incoming one — so an edit-guard on a
workflow field forbids legitimate transitions after computing its answer against the
wrong state. Enforce publish/transition policy with a validation constraint, which sees
the incoming value; guard `view` here freely.

## Relationship to Field Permissions

[`field_permissions`](https://www.drupal.org/project/field_permissions) solves an overlapping
problem with a per-field UI and three modes. Field Guard is deliberately narrower: no UI, one
mechanism, driven from configuration.

The substantive difference is the first section above — `field_permissions` returns
`neutral()` when `$items` is empty (`field_permissions.module`), which is the JSON:API filter
and Views handler-removal path. That is tracked upstream as
[#3003914](https://www.drupal.org/project/field_permissions/issues/3003914), reported in 2018
and confirmed with a reproduction in 2026. If that lands, the gap narrows considerably —
choose on whether you want a UI or a config file.

## Testing

```bash
vendor/bin/phpunit -c web/core modules/contrib/field_guard/tests
```

The one worth reading is `tests/src/Kernel/NullItemsFailsClosedTest.php`. It pins the
definition-level behaviour so this module cannot quietly acquire the failure mode it was
written to avoid.
