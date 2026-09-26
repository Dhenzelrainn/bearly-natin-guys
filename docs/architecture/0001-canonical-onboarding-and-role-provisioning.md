# ADR 0001: Canonical onboarding and role provisioning

- Status: Accepted
- Date: 2026-09-25
- Owners: Bearly platform team

## Context

The application currently has two onboarding representations:

1. Legacy registration and approval fields stored directly on `users`, including `role`, `status`, business fields, document paths, and approval metadata.
2. The normalized platform schema: `roles`, `role_user`, `account_applications`, `application_documents`, role profile tables, and `stores`.

The implemented registration controllers write only to `users`, while the normalized application and profile tables are empty. Admin approval currently activates the user but does not provision the normalized role or operational profile.

## Decision

### Source of truth by responsibility

- `users` owns authentication, identity, credentials, and account access state.
- `roles` and `role_user` own authorization.
- `account_applications` owns onboarding workflow state and the requested role.
- `application_documents` owns submitted-document metadata and verification state.
- `seller_profiles`, `logistics_profiles`, and `rider_profiles` own role-specific operational data.
- `stores` owns the Seller storefront and is provisioned in draft state after Seller approval.

`users.role` and the legacy application/document columns remain temporarily as compatibility fields. New code must not treat them as the long-term source of truth.

### Account and application states

`users.status` controls login access:

- `pending`, `needs_revision`: no role workspace access.
- `rejected`: no role workspace access.
- `active`: role workspace access is allowed only when the matching `role_user` assignment exists.
- `suspended`, `deactivated`, `banned`: no role workspace access.

`account_applications.status` controls review workflow:

- `draft` -> `submitted` -> `under_review`.
- `under_review` -> `needs_revision`, `approved`, or `rejected`.
- `needs_revision` -> `submitted` after resubmission.

An approval or rejection is a final decision for that application. A later reapplication creates a new application record rather than rewriting historical decisions.

### Registration transaction

Registration must perform the following as one database transaction after file validation/storage succeeds:

1. Create the `users` record with `status=pending`.
2. Resolve the requested `roles` record.
3. Create an `account_applications` record with a unique application number and `status=submitted`.
4. Create one `application_documents` record for each uploaded requirement.
5. Retain legacy `users` fields during the transition for existing views/controllers.

Uploaded files remain private. They are accessed only through authorized application-document endpoints.

### Approval transaction

Admin approval for Buyer, Seller, and Logistics must run in one transaction with row locking:

1. Confirm the application is reviewable and belongs to a non-active account.
2. Confirm required documents are present and verified.
3. Set the application to `approved`, including reviewer, decision reason, and timestamps.
4. Set `users.status=active` and retain legacy approval metadata during transition.
5. Attach the requested role in `role_user` with `assigned_by` and `assigned_at`.
6. Provision role data idempotently:
   - Buyer: no additional profile table is currently required.
   - Seller: create/update `seller_profiles`, then create a draft `stores` record.
   - Logistics: create/update `logistics_profiles`.
7. Write an append-only audit entry.
8. Dispatch the notification only after the transaction commits.

Approval must be safe to retry without creating duplicate profiles, stores, or role assignments.

### Rider sponsorship and approval

Rider approval remains owned by the sponsoring Logistics account.

- New Rider applications reference `sponsor_logistics_profile_id` on the application.
- On approval, create/update `rider_profiles` with the sponsoring `logistics_profile_id`.
- Attach the Rider role through `role_user` and activate the user in the same transaction.
- Super Admin may monitor or suspend Rider accounts but does not replace Logistics approval.

The legacy `users.logistics_id` remains temporarily for compatibility.

### Rejection and revision

- Rejection records reviewer, reason, and decision timestamp on the application and sets `users.status=rejected`.
- Requesting revision records revision notes, sets the application and user to `needs_revision`, and does not grant a role or create an operational profile.
- Previous documents remain immutable. Replacement uploads create new document records or an explicit supersession relationship in a later migration; files are not silently overwritten.

### Authorization during transition

`User::hasRole()` may continue checking both `users.role` and `role_user` while backfill is in progress. After all active accounts have normalized assignments and controllers no longer depend on `users.role`, authorization will use `role_user` only.

## Data invariants

- An active operational account must have its matching `role_user` assignment.
- An approved Seller must have one Seller profile and one store.
- An approved Logistics account must have one Logistics profile.
- An approved Rider must have one Rider profile linked to its sponsoring Logistics profile.
- A user cannot have two simultaneously reviewable applications for the same requested role.
- Application decisions, document verification, role assignment, profile provisioning, audit logging, and user activation must not be partially committed.
- Admin and document routes must remain protected by authentication and role authorization.

## Backfill plan

Implement an idempotent Artisan command with `--dry-run` as the default behavior.

For each existing user:

1. Resolve the legacy `users.role` to `roles.name`.
2. For active users, add the missing `role_user` assignment.
3. Create a historical application only when enough legacy data exists to represent it accurately; mark it as imported in a structured note/metadata strategy decided during implementation.
4. Import document records only when the referenced file exists and its required metadata can be determined. Do not fabricate document metadata.
5. Provision missing Seller/Logistics/Rider profiles only when required fields and valid relationships exist; otherwise report the account for manual remediation.
6. Emit a machine-readable summary without exposing document contents or passwords.

Current production-data scope at acceptance:

- One active Admin already has its `role_user` assignment.
- One active Buyer needs a Buyer role assignment.
- There are no normalized applications, documents, role profiles, or stores to migrate.

The command must be tested against a database copy before it is allowed to update `bearly_ecommerce`.

## Migration sequence

1. Add services and tests for canonical registration and application review.
2. Add the dry-run backfill command and verify its report.
3. Back up the database, run the backfill, and verify invariants.
4. Switch Admin registration pages to normalized applications/documents.
5. Switch role checks and downstream controllers to normalized roles/profiles.
6. Remove legacy writes only after all readers have migrated.
7. Remove legacy columns in a separate, explicitly reviewed future migration.

## Testing requirements

- Registration creates user, application, and document records atomically.
- Guests and pending/rejected/inactive users cannot enter role workspaces.
- Approval provisions exactly one normalized role/profile/store even when retried.
- Rejection and revision never provision operational role data.
- Failed provisioning rolls back the complete decision.
- Logistics cannot approve Riders sponsored by another Logistics account.
- Document endpoints reject unauthorized users and path traversal attempts.
- Notifications are dispatched only after successful commit.
- Every administrative decision writes an audit record.

## Consequences

This decision adds temporary dual writes during migration, but removes ambiguity about ownership of account, application, authorization, and profile data. It also permits Phase 1 to be implemented incrementally without deleting legacy columns or breaking the current role interfaces.
