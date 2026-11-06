# Individual membership family restriction

Implemented and deployed on 2026-10-30 as `sports-update-20261030T0215PKT`. See [deployment evidence](deployment-20261030.md).

Individual memberships cannot add dependents, including dependents linked to an existing family member. FamilyMemberController applies the rule to staff, member portal and mobile API creation requests, returning HTTP 422 with code FAMILY_MEMBERS_NOT_ALLOWED. It reloads and locks the sponsor in a transaction before checking eligibility and inserting.

Classification checks individual category names and legacy individual accounts 260110 / 260115, including original accounts on frozen memberships. Legacy records without a category also check active or frozen Individual plans. Other categories retain their existing behavior; this change does not assign new entitlement rules to unclassified records.

Existing family records, photographs, documents and card history remain intact. List APIs retain the original array response by default. With include_eligibility=1 they return members, can_add_family_members and restriction. The staff/portal component requests this metadata, hides the entry form when restricted and reloads when staff change the sponsor category/account/status. Creation always checks current server state.

Validation: php artisan test --compact: 135 tests passed, 988 assertions. Tests cover both individual categories, frozen sponsors, nested entry attempts, legacy account/plan classification, account remapping, both family categories, staff/portal/mobile access and existing family preservation. npm run build passed with the existing chunk size advisory. Targeted Pint passed. Subsequent release verification confirmed the live HTTP 422 restriction for both individual accounts, and the UI restriction was visually verified with a synthetic member. See the deployment evidence.
