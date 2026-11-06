# Primary-member family form — 30 October 2026

New family records link directly to the primary member. The staff and portal form shows a fixed Primary member label and a required dropdown: S/O — Son, W/O — Wife, D/O — Daughter, Son-in-law, Daughter-in-law. Stored relationship values are Son, Wife, Daughter, Son-in-law and Daughter-in-law.

The shared controller rejects a nonempty parent_family_member_id and rejects relationships outside the five choices. The primary member comes from the authorized route, and parent_family_member_id is explicitly null on creation. Staff complex scope, portal ownership, and the prohibition for individual memberships remain enforced. Existing historical relationships are not rewritten; nested historical records and cards remain readable.

Validation: 152 tests / 1,171 assertions, Pint and production Vite build passed. Tests cover all five choices for staff and portal, reject nested links and unsupported relationships, and retain individual-member, ownership and card-access coverage. Mobile clients using this controller must send one of the canonical relationship values and omit the parent field or send null.

Release marker: sports-family-primary-20261030. Backup/rollback directory on lda-web: /var/backups/sports-family-primary-20261030/.
