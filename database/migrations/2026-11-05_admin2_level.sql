-- ============================================================
-- 2026-11-05 — Admin 2 (staff.transferpicklist = 2)
--
-- transferpicklist = 2 now means "Admin 2": Warehouse Returns, Procurement List
-- and Exclusions → Campaign only. Set it from Admin Settings
-- (transferpicklist_admin.php) — no schema change.
--
-- BUT the old system also used 2. Anyone still on 2 from back then would get
-- Admin 2 access the moment this is deployed. Run STEP 1 BEFORE deploying.
--
-- Run on: production odb, in phpMyAdmin → SQL tab.
-- ============================================================

-- STEP 1 — Who is on 2 right now? (old values, from before this change)
SELECT s.id, s.nama_staff, s.username, d.depart_name, s.status_semasa
FROM staff s
LEFT JOIN staff_department d ON d.id = s.department
WHERE s.transferpicklist = 2 AND s.recycle != 1
ORDER BY s.nama_staff;

-- STEP 2 — Only if STEP 1 lists people who should NOT be Admin 2:
-- reset them all to 0, then add the right people again from Admin Settings.
-- (Leave commented if STEP 1 is empty or everyone listed should be Admin 2.)
-- UPDATE staff SET transferpicklist = 0 WHERE transferpicklist = 2;
