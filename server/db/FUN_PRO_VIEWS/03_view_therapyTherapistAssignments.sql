-- =====================================================
-- Therapy Therapist Assignments View
-- Shows which therapists are assigned to which patient groups.
--
-- Used by:
-- - Admin pages to manage therapist-group assignments
-- - Dashboard to determine which conversations a therapist can see
-- =====================================================

CREATE OR REPLACE VIEW `view_therapyTherapistAssignments` AS
SELECT
    tta.id_users,
    tta.id_groups,
    tta.assigned_at,
    u.name AS therapist_name,
    u.email AS therapist_email,
    g.name AS group_name
FROM therapyTherapistAssignments tta
INNER JOIN users u ON u.id = tta.id_users
INNER JOIN `groups` g ON g.id = tta.id_groups;
