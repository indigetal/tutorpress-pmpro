# PMPro ↔ TutorLMS Integration: Methods to copy/modify and hook points

Purpose: a focused checklist for the integration work—exact methods to reuse from
`references/tutorlms.php` (PMPro "Courses for Membership" addon) and where to implement
or hook them into `tutorpress-pmpro` so we can support Course Bundles and membership-aware
behavior without modifying Tutor Pro core.

Files referenced:

- `references/tutorlms.php` (source)
- Our plugin targets: `includes/init.php`, `includes/PaidMembershipsPro.php`, `includes/rest/class-pmpro-subscriptions-controller.php`

Summary of source methods and how to reuse them

- admin_menu()

  - Source: `references/tutorlms.php::admin_menu`
  - Purpose: add PMPro "Require Membership" meta box to Tutor course editor
  - Where to implement: `includes/PaidMembershipsPro.php` (constructor already registers hooks)
  - Hook into: `add_action('admin_menu', ...)` or better: `add_action('add_meta_boxes', ...)` when loading course editor
  - Notes: call `pmpro_page_meta()` (if available) and guard with `function_exists('pmpro_page_meta')`.

- template_redirect()

  - Source: `references/tutorlms.php::template_redirect`
  - Purpose: when a lesson/topic/quiz is requested, map to parent course and redirect if membership required
  - Where to implement: `includes/PaidMembershipsPro.php` (add action in `init()` / class constructor)
  - Hook into: `add_action('template_redirect', [ $this, 'template_redirect' ])`
  - Modifications: use `tutor_utils()->get_course_id_by()` and our `pmpro_has_membership_access()` wrappers; preserve filters used in source.

- has_access_to_post() and pmpro_has_membership_access_filter()

  - Source: `references/tutorlms.php::has_access_to_post` and `pmpro_has_membership_access_filter`
  - Purpose: centralize access decision for course/lesson/topic/quiz using PMPro membership levels
  - Where to implement: `includes/PaidMembershipsPro.php` as public methods (e.g., `has_course_access()`);
  - Hook into: `add_filter('pmpro_has_membership_access_filter', [ $this, 'pmpro_has_membership_access_filter' ], 10, 4)`
  - Modifications: remove/add filter around `pmpro_has_membership_access()` calls to avoid recursion (follow the `remove_filter` pattern used in source)

- pmpro_membership_content_filter()

  - Source: `references/tutorlms.php::pmpro_membership_content_filter`
  - Purpose: override PMPro content filter for courses to merge Tutor content + PMPro no-access message
  - Where to implement: `includes/PaidMembershipsPro.php`
  - Hook into: `add_filter('pmpro_membership_content_filter', [ $this, 'pmpro_membership_content_filter' ], 10, 2)`

- get_courses_for_levels()

  - Source: `references/tutorlms.php::get_courses_for_levels`
  - Purpose: utility to map PMPro membership level IDs → Tutor course IDs (DB query optimized for PMPro tables)
  - Where to implement: `includes/PaidMembershipsPro.php` (private helper)
  - Usage: used by level-change handlers to enroll/unenroll users

- pmpro_after_all_membership_level_changes()
  - Source: `references/tutorlms.php::pmpro_after_all_membership_level_changes`
  - Purpose: when users' PMPro levels change, enroll/unenroll them to associated private courses
  - Where to hook: `add_action('pmpro_after_all_membership_level_changes', [ $this, 'pmpro_after_all_membership_level_changes' ])` inside `includes/PaidMembershipsPro.php` or `includes/init.php` (during load)
  - Modifications: ensure the function uses `tutor_utils()->do_enroll()` / `tutor_utils()->cancel_course_enrol()` APIs present in TutorPress/Tutor LMS

Integration & hook placement decisions (exact files and hooks)

- `includes/PaidMembershipsPro.php`

  - Add/extend methods:
    - `public function admin_menu()` — register meta box (call `pmpro_page_meta`)
    - `public function template_redirect()` — implement redirect logic
    - `public function has_access_to_post( $post_id, $user_id = null )` — wrapper that calls PMPro access and Tutor utilities
    - `public function pmpro_has_membership_access_filter( $hasaccess, $mypost, $myuser, $post_membership_levels )` — filter implementation
    - `private function get_courses_for_levels( $level_ids )` — DB helper
    - `public function pmpro_after_all_membership_level_changes( $pmpro_old_user_levels )` — enroll/unenroll handler
  - Register hooks in constructor (guarded by `function_exists('pmpro_getAllLevels')` and `tutor_utils()` presence)

- `includes/init.php`

  - Ensure the PaidMembershipsPro class is instantiated early when PMPro is present (already done). Add early registration so the above filters are attached before tutorpro builds lists where needed.
  - If needed, add a small shim to register the `pmpro_page_meta` meta box on `add_meta_boxes` priority after Tutor loads.

- `includes/rest/class-pmpro-subscriptions-controller.php`
  - No direct copy from `references/tutorlms.php` but the access helper methods above should be used by controller routes to check access where relevant.

Implementation notes / modifications

- Name collisions: adapt function/class names to the `TUTORPRESS_PMPRO\\` namespace and keep methods as class methods (do not create global functions unless necessary).
- Defensive guards: always check for `function_exists('pmpro_getAllLevels')`, `function_exists('pmpro_page_meta')`, and `function_exists('tutor_utils')` before calling.
- Avoid dynamic property assignment to `tutor()`; prefer filters or helper methods.
- Maintain WP coding standards and keep changes lint-safe.

Testing checklist (manual)

1. Admin meta box: Edit any course → confirm 'Require Membership' meta box appears and saves level selections.
2. Lesson redirect: Visit a lesson that is protected → verify redirect to parent course (when access is denied).
3. Access checks: Verify `has_access_to_post()` returns correct boolean for course/lesson for PMPro members and non-members.
4. Level sync: Change a user's PMPro levels and confirm they are enrolled/unenrolled from associated private courses.
5. Frontend display: Confirm course bundle entries show on `/dashboard/my-courses/` and backend `/wp-admin/admin.php?page=tutor` when PMPro monetization is selected.

References

- Source implementation: `references/tutorlms.php` (review closely the DB query in `get_courses_for_levels()` and the `pmpro_after_all_membership_level_changes()` implementation)

If you want, I will: (pick one)

- A) Implement these methods directly (create class methods in `includes/PaidMembershipsPro.php`) and wire hooks in `includes/init.php`; or
- B) Draft a PR/edits showing exact diffs with small, well-documented patches for each method above.
