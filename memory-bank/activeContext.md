Active context for current work:

Plan to update delete handler and add safety nets

- Where to edit: `includes/rest/class-pmpro-subscriptions-controller.php::delete_subscription_plan()`

  - Also wire course-level cleanup triggers in:

    - `includes/init.php` via `rest_after_insert_courses` (priority ≥ 20), `save_post_courses` (priority ≥ 999), and `transition_post_status` for courses
    - Mirror for bundles if applicable: `rest_after_insert_course-bundle`, `save_post_course-bundle`

  - Keep `pmpro_deleteMembershipLevel()` (or direct delete) as primary removal.
  - After delete (in both branches):
    - Remove associations: `\TUTORPRESS_PMPRO\PMPro_Association::remove_associations_for_level( $level_id )`
    - Remove level meta: `$wpdb->delete( $wpdb->pmpro_membership_levelmeta, ['pmpro_membership_level_id' => $level_id], ['%d'] );`
    - Remove category relations: `$wpdb->delete( $wpdb->pmpro_memberships_categories, ['membership_id' => $level_id], ['%d'] );`
    - Prune `_tutorpress_pmpro_levels` on any course referencing this id (present; keep).

---

Lessons from failed attempt (applied to new plan)

- Cleanup didn’t consistently run from Gutenberg saves; rely on both REST and classic save hooks.
- Reading Tutor meta too early caused the “free” branch to be skipped; for REST use `$request->get_param()`, for classic saves run very late or schedule a delayed reconcile.
- Gating deletes on `tutorpress_managed` missed legacy levels; auto-cleanup path must delete regardless of managed flags.
- Duplicates occurred when old levels weren’t deleted before create; enforce delete-first, then update-or-insert idempotently.
- `_tutorpress_pmpro_levels` can be stale; reconcile from `pmpro_memberships_pages` each run and then write back.

---

Reworked delete handler plan (course pricing changes)

- Triggers

  - `rest_after_insert_courses` (priority 20): read selling/price directly from `$request` with fallbacks to `get_post_meta()`
  - `save_post_courses` (priority 999): schedule `wp_schedule_single_event( time() + 1, 'tp_pmpro_reconcile_course', [ $post_id ] )`
  - `transition_post_status`: schedule reconcile only when transitioning to `publish`

- Reconcile function (single source of truth)

  - Input: `$course_id`, optional context data from REST
  - Build association sets from DB (source of truth):
    1. Query `pmpro_memberships_pages` for course associations
    2. Verify each id exists in `pmpro_membership_levels`
    3. Classify into `$one_time_ids` vs `$recurring_ids`
    4. Cross-check `_tutorpress_pmpro_levels` (log discrepancies) and write back at the end
  - Determine current `price_type` and `selling_option`
  - Branch logic (unconditional deletes in this path):
    - `price_type === free`: delete all associated levels; clear associations and `_tutorpress_pmpro_levels`
    - `selling_option === subscription`: delete all one-time levels; keep/update recurring ids
    - `selling_option === one_time`: delete all recurring levels; ensure exactly one one-time level exists (update-or-insert)
  - After operations: rebuild `_tutorpress_pmpro_levels` from current associations

- Deletion behavior

  - Use `\TUTORPRESS_PMPRO\PMPro_Level_Cleanup::full_delete_level( $level_id, true )` for all levels discovered via association/meta
  - Do not require `tutorpress_managed` in this path; managed flags remain useful for provenance but not for deletion

- Idempotency & duplicate prevention

  - Before inserting one-time level, search for existing one-time by association and by PMPro fields; update if found
  - Apply a short-lived lock (transient `tp_pmpro_lock_{course_id}`) during reconcile to prevent concurrent duplicate inserts
  - Always re-derive `_tutorpress_pmpro_levels` from associations at the end

- Observability

  - Log: hook name, course id, detected `price_type`/`selling_option`, counts of valid/one-time/recurring ids, decisions taken, number of deletions and inserts/updates

- Safety nets
  - Keep lazy cleanup during display for missing level ids
  - Add an admin action: “Reconcile PMPro Levels for Course” to run the reconcile on demand

---

Hook registrations (concise)

```php
// In includes/init.php
add_action( 'rest_after_insert_courses', [ $this, 'reconcile_course_levels_rest' ], 20, 2 );
add_action( 'save_post_courses', [ $this, 'schedule_reconcile_course_levels' ], 999, 3 );
add_action( 'transition_post_status', [ $this, 'maybe_reconcile_on_status' ], 20, 3 );
add_action( 'tp_pmpro_reconcile_course', [ $this, 'reconcile_course_levels' ], 10, 1 );
```

Minimal reconcile flow (pseudocode)

```php
function reconcile_course_levels( $course_id, $ctx = [] ) {
    // derive price_type/selling_option from $ctx (REST) or get_post_meta
    // fetch association sets
    // branch per plan above; use PMPro_Level_Cleanup::full_delete_level
    // update-or-insert one-time level if needed; tag managed meta
    // rebuild _tutorpress_pmpro_levels from associations
}
```

- Lazy cleanup on display (archive/single):

  - When an associated PMPro level id returns `null` from `pmpro_getLevel($id)`, immediately:
    - Delete rows from `pmpro_memberships_pages` for that id (and optionally just for current course).
    - Remove the id from the course’s `_tutorpress_pmpro_levels` meta.
  - Continue rendering with remaining valid ids to keep UI correct.

- Managed-level tagging (future-safe):

  - When creating levels via our plugin, set level meta `tutorpress_managed=1` and `tutorpress_course_id=<course_id>`.
  - Reconcile on course save/selling_option change: remove managed levels that are no longer desired and have zero associations.

- Safety net on display (recommended)

  - We already skip missing levels. Add a lazy cleanup queue when `pmpro_getLevel($id)` returns null:
    - Remove that id from `pmpro_memberships_pages`,
    - Remove from `_tutorpress_pmpro_levels` on the current course.
  - This makes UI correct and gradually heals DB.

- Add a `PMPro_Level_Cleanup::reconcile_course($course_id)` and `PMPro_Level_Cleanup::delete_level($level_id)` utility and wire:
  - reconcile on course save/selling_option change,
  - delete hooks on level delete,
  - nightly cron to prune orphans.

---

**Note: Sale price handling (deferred)**
Paid Memberships Pro does not natively support a separate "sale price" field. We will implement sale-price UX later as an integration enhancement: store sale values in PMPro level meta, and (optionally) use a PMPro display filter or the pmpro-subscription-delays recipe to show sale start/end behaviour. See references/tutorlms.php and references/pmpro-subscription-delays for reference implementations. This work will be scheduled in Phase 2 of the PMPro integration (post-4F) and will include UI, mapping, and display/docs tasks.

---

## NEXT:

- When the pricing type is changed to free in Tutor LMS's frontend course builder it does not trigger the deletion of existing PMPro Level's like it does when the pricing type is changed in Gutenberg.

### Implementation Challenges:

- "Subscription & one-time purchase" option (potentially redundant vs subscription with initial payment?)
- Sale pricing on both initial payment AND recurring subscription payment
- Level naming conflicts (multiple courses could create levels with same name)

### Technical Strategy:

Based on codebase analysis, we'll implement:

1. **PMPro Level Manager Class**: Use `PMPro_Membership_Level` class and `pmpro_insert_or_replace()` for CRUD operations
2. **REST API Hooks**: Intercept TutorPress course settings saves via `rest_api_init` and `pre_update_post_meta` hooks
3. **Field Mapping Engine**: Transform TutorPress pricing data to PMPro level structure
4. **Course-Level Association**: Store course_id → level_id mapping in course post meta and level meta
5. **Dynamic Level Naming**: Use pattern like "Course: {title} - {plan_name}" to avoid conflicts

---

Incremental Implementation Plan: Delete Handler Rework (commit-by-commit)

0. Create PMPro_Level_Cleanup utility class

   - Implement `full_delete_level( $level_id, $delete_level_if_exists )`:
     - Delete from `pmpro_membership_levels` (when `$delete_level_if_exists`)
     - Delete from `pmpro_memberships_pages` (associations)
     - Delete from `pmpro_membership_levelmeta`
     - Delete from `pmpro_memberships_categories`
     - Prune `_tutorpress_pmpro_levels` on all courses that reference the id
   - Implement `remove_course_level_mapping( $course_id, $level_id )` for lazy cleanup
   - Test: Manually call methods and verify full cleanup in all tables and course meta
   - Commit: "Add PMPro_Level_Cleanup utility for comprehensive level deletion"

1. Wire entrypoints and scaffolding

   - Add hooks:
     - `rest_after_insert_courses` (priority 20) → `reconcile_course_levels_rest( $post, $request )`
     - `save_post_courses` (priority 999) → `schedule_reconcile_course_levels( $post_id, $post, $update )`
     - `transition_post_status` (priority 20) → `maybe_reconcile_on_status( $new, $old, $post )`
     - `tp_pmpro_reconcile_course` (action) → `reconcile_course_levels( $course_id, $ctx = [] )`
   - Add stubs for these methods with debug logs.
   - Checkpoint: Save a course in Gutenberg; verify logs show the REST hook fired. Save in classic; verify scheduler fired.
   - Commit: "Hook scaffolding for course level reconcile (REST/save/transition)"

2. ✅ Implement association + context extraction (COMPLETE)

   **Implemented:**
   - Created `get_course_pmpro_state()` private utility method that:
     - Discovers associations from `pmpro_memberships_pages` (primary source of truth)
     - Also reads `_tutorpress_pmpro_levels` meta (secondary source)
     - Verifies each candidate level exists in `pmpro_membership_levels`
     - Classifies levels as `one_time` (billing_amount=0 & cycle_number=0) or `recurring`
     - Prunes stale associations using `PMPro_Level_Cleanup::remove_course_level_mapping()`
     - Rewrites `_tutorpress_pmpro_levels` meta to match verified valid IDs
     - Returns `array( 'valid_ids', 'one_time_ids', 'recurring_ids' )`
   - Updated `reconcile_course_levels_rest()` to extract context from REST request with fallback to post meta
   - Updated `reconcile_course_levels()` to:
     - Call `get_course_pmpro_state()` after processing pending plans
     - Read course pricing context (`selling_option`, `price_type`, `price`) from post meta
     - Log all discovered associations, classifications, and context inputs
   - Enhanced pending plan creation to always set:
     - Reverse ownership meta: `tutorpress_course_id` and `tutorpress_managed`
     - Association row via `PMPro_Association::ensure_course_level_association()`
     - Logs confirm both operations complete for each created level
   - Commit: "Reconcile: association discovery and context extraction"

   Key insights to carry forward so deletion is reliable (esp. for subscriptions):

   - Ensure reverse ownership meta is always set

     - On level creation (both one-time and subscription), always set `tutorpress_course_id` on the PMPro level.
     - Missing ownership meta caused the delete handler (older logic) to skip deletion for subscriptions.

   - Ensure association rows always exist

     - Always insert `pmpro_memberships_pages (membership_id, page_id)` for the course when creating the level.
     - Subscriptions created during reconcile must call `PMPro_Association::ensure_course_level_association()` and set ownership meta in the same transaction.

   - Derive levels to delete from multiple sources

     - On permanent delete, collect IDs from ALL of:
       - `pmpro_memberships_pages` where `page_id = course_id`
       - `_tutorpress_pmpro_levels` post meta
       - PMPro level meta where `tutorpress_course_id = course_id`
     - Union + unique → delete. This protects against missing rows in any single source.

   - Avoid conditional deletion on shared-level heuristics

     - The “owned or only one association” rule let subscription levels survive. If the desired behavior is “delete everything associated to this course,” do so unconditionally on permanent delete.

   - Timing and race conditions

     - Ensure reconcile runs (scheduled after publish) before a user deletes the course. We already schedule reconcile at publish; keep it and add logs so we can confirm it finished.

   - Observability to prevent regressions

     - In the delete handler, log:
       - collected level IDs,
       - for each: `owner`, `assoc_count`,
       - action taken (deleted vs unmapped).
     - In reconcile: log after creation that reverse meta and association were written.

   - Self-heal on display and save paths
     - Keep pruning of stale associations/meta and rewriting `_tutorpress_pmpro_levels` from truth so the delete handler sees accurate data.

   Implementing these ensures subscription plans get deleted with the course, even if prior steps missed an association or ownership meta.

3. Free branch (authoritative deletion)

   - If `price_type === 'free'`: delete all levels via `PMPro_Level_Cleanup::full_delete_level( $id, true )` for each id in `$valid_ids`.
   - Clear associations and `_tutorpress_pmpro_levels`.
   - Test: Create one-time level, switch to Free in Gutenberg; confirm the level row is removed in PMPro and meta cleared.
   - Commit: "Reconcile: Free branch deletes all associated levels"

4. Subscription-only branch

   - If `selling_option === 'subscription'`: delete all `$one_time_ids`; keep `$recurring_ids`; update `_tutorpress_pmpro_levels` with kept ids.
   - Test: Toggle one-time → subscription; verify one-time deleted, recurring preserved.
   - Commit: "Reconcile: Subscription-only removes one-time levels"

5. One-time-only branch

   - If `selling_option === 'one_time'`: delete all `$recurring_ids`.
   - Ensure exactly one one-time level exists:
     - If found: update fields; else insert new; tag `tutorpress_managed=1` and `tutorpress_course_id` and ensure association row exists.
   - Update `_tutorpress_pmpro_levels` to single id.
   - Test: Toggle subscription → one-time; verify recurring deleted, single one-time created/updated, no duplicates.
   - Commit: "Reconcile: One-time-only removes recurring and upserts single one-time level"

6. Idempotency and lock

   - Add short-lived transient lock `tp_pmpro_lock_{course_id}` within reconcile to avoid concurrent double-runs.
   - Always rebuild `_tutorpress_pmpro_levels` from associations at end.
   - Test: Rapidly save the course twice; verify no duplicate levels.
   - Commit: "Reconcile: add transient lock and meta rebuild"

7. Scheduled reconcile path

   - Implement `schedule_reconcile_course_levels` to enqueue `tp_pmpro_reconcile_course` 1s later with `$course_id`.
   - Implement `maybe_reconcile_on_status` to schedule when transitioning to `publish`.
   - Test: Classic save and status change trigger reconcile.
   - Commit: "Reconcile: scheduled path for classic saves/status transitions"

8. Admin on-demand action

   - Add a small admin action/link to run `reconcile_course_levels( $course_id )` from the course row/actions.
   - Test on a stale course; verify cleanup occurs.
   - Commit: "Admin: Reconcile PMPro Levels for Course action"

9. Observability

   - Gate logs behind a constant (e.g., `TP_PMPRO_LOG`); add concise, structured logs for inputs, decisions, and effects.
   - Commit: "Reconcile: add structured debug logs (gated)"

10. Final cleanup and docs

- Remove any obsolete guards relying on `tutorpress_managed` in reconcile path (keep meta writes for provenance).
- Update docs with final behavior and test steps.
- Commit: "Reconcile: finalize cleanup and update docs"

---

Future Work

It seems Tutor LMS's Paid Membership Pro addon allows for setting subscriptions globally by the site admin. In Memberships > Settings > Levels > Add New, I see a "Tutor LMS Content Settings" section with a "Membership Model" dropdown that includes the options "Full Website Membership" and "Category Wise Membership." So the "Membeships only" option can be integrated with that.

---

We also need to integrate Paid Memberships Pro with the frontend functionality of Tutor LMS's "native" commerce engine and Subscriptions addon where necessary.

---

Current focus (frontend pricing integration — minimal, hook-accurate)

- Objective: Display PMPro-based prices in Tutor archive cards, dashboard "My Courses", and minimally integrate the single entry box while preserving Tutor templates.
- Implementation details:
  - Step B: Implement `get_tutor_course_price` at priority 12 to return PMPro minimal price strings from `PMPro_Pricing` (engine + level guards).
  - Step C: Implement `tutor_course_loop_add_to_cart_button` at priority 12 to return a "View Details" link when selling_option is subscription-like and PMPro levels exist; otherwise return original HTML.
  - Step D: Implement `tutor/course/single/entry-box/purchasable` at priority 12; leave `one_time` intact, replace others with minimal PMPro plan block.
  - Keep `tutor_course_sell_by` returning `tutor` only when course has PMPro levels to ensure loop wrappers render; avoid replacing `tutor_course_loop_price`.
  - Avoid fallback injections unless template overrides block output; keep them disabled by default.
- Testing: Verify archive/dashboard prices, loop CTA behavior, and single entry box replacement per selling_option; confirm engine switching restores Tutor defaults.
