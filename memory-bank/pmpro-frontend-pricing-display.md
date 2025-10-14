PMPro frontend pricing display — parity with Tutor LMS native commerce and Subscriptions

Scope

- Replicate native pricing visuals/strings for: 1) course archive cards, 2) single course entry box, 3) frontend dashboard "My Courses" list — when PMPro is the monetization engine.
- Use Tutor LMS's existing hooks to replace content only when needed and keep Tutor's markup/structure.

Hook map (what Tutor actually uses)

- Archive/grid/list

  - `tutor_course/loop/footer` → calls `tutor_course_loop_price()`
  - `tutor_course_loop_price()` chooses template:
    - If `apply_filters('tutor_course_sell_by', ...)` returns a value and course is purchasable → loads `loop/course-price-{sell_by}.php`
    - Else → loads `loop/course-price.php`
  - Inside templates:
    - Price string comes from `tutor_utils()->get_course_price()` → `apply_filters('get_tutor_course_price', $price, $course_id)`
    - CTA button in Tutor monetization path calls `tutor_course_loop_add_to_cart()` → template `loop/add-to-cart-tutor.php` → `apply_filters('tutor_course_loop_add_to_cart_button', $html, $course_id)`
  - Footer exposes `do_action('tutor_course_loop_footer_bottom', $course_id)` for last-resort injections

- Dashboard “My Courses”

  - Template prints `tutor_utils()->get_course_price()` directly (so `get_tutor_course_price` is the point to inject)

- Single course entry box
  - Template collects `$price = apply_filters('get_tutor_course_price', null, get_the_ID())`
  - If purchasable and `$tutor_course_sell_by` is set → loads `single/course/add-to-cart-{sell_by}.php` then filters result via `apply_filters('tutor/course/single/entry-box/purchasable', $html, $course_id)`

Tutor Pro Subscriptions behavior (for parity)

- Filters
  - `get_tutor_course_price` (10): injects minimal price string (archive, dashboard, single)
  - `tutor_course_loop_add_to_cart_button` (10): swaps CTA to “View Details” when subscription-like selling options
  - `tutor/course/single/entry-box/purchasable` (12): replaces entry box with subscription/membership plans unless `SELLING_OPTION_ONE_TIME`

PMPro display model

1. Single one-time level

- Archive/Dashboard: "$X one-time"
- Single: Keep Tutor’s entry box; CTA to PMPro checkout (future enhancement)

2. Single recurring level

- Archive/Dashboard: "$X/month" (per period)
- Single: Replace entry box with simple plan UI and Buy Now → PMPro checkout

3. Multiple levels (any mix)

- Archive/Dashboard: "Starts from $X"
- Single: Replace entry box with multiple selectable plans + Buy Now

4. Public/Free

- All contexts: "Free"

Implementation plan (final)

Step A — Price formatting helper (server) ✅ COMPLETED

- `PMPro_Pricing::get_formatted_price($course_id)` → returns minimal string (“Free”, “$X one-time”, “$X/period”, “Starts from $X”)

Step B — Archive + Dashboard price injection (final)

- Primary Hook (revised for reliability): `tutor_course_loop_price` (priority 12) — directly return minimal markup for mapped PMPro courses (price + “View Details”).
- Support Hook: `get_tutor_course_price` (priority 12) — compute/return the minimal PMPro price string used by Step B and other contexts.
- Optional: `tutor_course_sell_by` no longer required for archive but may remain for parity; we won’t rely on it for archive rendering.

Step C — Archive button override (final)

- Hook: `tutor_course_loop_add_to_cart_button` (priority 12)
- Guard: course has PMPro levels AND `Course::get_selling_option()` in [subscription, both, membership, all]
- Return: "View Details" link to course permalink; otherwise, return original `$html`

Step D — Single course entry box (minimal parity)

- Hook: `tutor/course/single/entry-box/purchasable` (priority 12)
- Guard: course has PMPro levels
- Logic:
  - If `selling_option === 'one_time'` → return `$html` unchanged (keep Tutor native)
  - Else (`subscription|both|membership|all`) → replace with minimal PMPro plan block
    - Single level: show price and “Buy Now” → PMPro checkout URL for that level
    - Multiple levels: radio-select plans + “Buy Now” for selected level

Step E — Engine/visibility guards ✅

- `is_pmpro_enabled()` ensure hooks only run when PMPro monetization is active
- Respect Public/Free (return “Free”)

What to remove/avoid

- Do NOT replace full loop price block via `tutor_course_loop_price`
- Avoid fallback injections (`tutor_course_loop_footer_bottom`, `tutor_course/loop/after_footer`) unless theme overrides break templates; keep off by default

Hook registrations (precise)

- Register on `wp` action (priority 20)
  - `add_filter('tutor_course_loop_price', [PaidMembershipsPro, 'filter_course_loop_price_pmpro'], 12, 2)`
  - `add_filter('get_tutor_course_price', [PaidMembershipsPro, 'filter_get_tutor_course_price'], 12, 2)`
  - `add_filter('tutor_course_loop_add_to_cart_button', [PaidMembershipsPro, 'filter_course_loop_button'], 12, 2)`
  - `add_filter('tutor/course/single/entry-box/purchasable', [PaidMembershipsPro, 'filter_single_entry_box'], 12, 2)`

Testing checklist

Archive

- 1 one-time → “$X one-time” + “View Details” only when selling_option is subscription-like (else native)
- 1 recurring → “$X/period” + “View Details”
- Multiple levels → “Starts from $X” + “View Details”
- Free → “Free”

Dashboard “My Courses”

- Price shows per PMPro string

Single

- One-time: native entry box
- Subscription-like: minimal PMPro plan UI; Buy Now → PMPro checkout

Engine switch

- Switching away from PMPro restores Tutor defaults

Current status

- ✅ Step A completed
- ⬜ Step B pending (implement `tutor_course_loop_price` override + `get_tutor_course_price` support)
- ⬜ Step C pending (CTA override at priority 12)
- ⬜ Step D pending (entry box filter at priority 12)
- ✅ Engine guards in place
