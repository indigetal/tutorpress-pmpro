1. Calls to `tutor_utils()->is_monetize_by_tutor()` (affects runtime gating / native-only branches)

- `references/tutor-pro/addons/course-bundle/src/Init.php` — L129
- `references/tutor-pro/addons/course-bundle/src/Models/BundleModel.php` — L765, L811
- `references/tutor-pro/addons/enrollments/classes/Enrollments.php` — L351, L381, L392, L520
- `references/tutor-pro/addons/subscription/src/Controllers/FrontendController.php` — L543
- `references/tutor-pro/addons/subscription/src/Controllers/ManualSubscriptionController.php` — L96
- `references/tutor-pro/addons/subscription/src/Subscription.php` — L49, L53
- `references/tutor-pro/gift-course/EventHandler.php` — L140
- `references/tutor-pro/gift-course/GiftCourse.php` — L384
- `references/tutor-pro/classes/Assets.php` — L115
- `references/tutor-pro/addons/tutor-report/views/pages/sales/sales-page.php` — L50, L95
- `references/tutor-pro/addons/tutor-report/classes/Analytics.php` — L552, L660, L961
- `references/tutor-pro/ecommerce/Init.php` — L39
- `references/tutor-pro/addons/subscription/src/AddonRegister.php` — L38
- (Also many occurrences inside `references/tutor-master/*` utilities and `classes/Utils.php` used by core; see next section)

2. Calls to `get_option('monetize_by')`, `tutor_utils()->get_option('monetize_by')`, or equivalent (reads the raw setting)

- `references/tutor-pro/addons/course-bundle/src/Init.php` — L53, L126
- `references/tutor-pro/addons/course-bundle/src/CustomPosts/ManagePostMeta.php` — L74
- `references/tutor-pro/addons/course-bundle/src/Integrations/WooCommerce.php` — L32
- `references/tutor-pro/addons/enrollments/classes/Enrollments.php` — L304
- `references/tutor-pro/addons/pmpro/classes/PaidMembershipsPro.php` — L677
- `references/tutor-pro/gift-course/InitGift.php` — L89
- `references/tutor-pro/gift-course/GiftCourse.php` — L184, L309
- `references/tutor-pro/gift-course/EventHandler.php` — L239
- `references/tutor-pro/addons/tutor-report/classes/Analytics.php` — L964
- `references/tutor-pro/templates/frontend-course-builder.php` — L183
- `references/tutor-pro/addons/wc-subscriptions/classes/init.php` — L40
- `references/tutor-pro/addons/restrict-content-pro/classes/init.php` — L40
- `references/tutor-pro/addons/pmpro/classes/init.php` — L42

3. Core/shared utility locations (important because changing behavior here covers many spots)

- `references/tutor-master/classes/Utils.php` — multiple lines around: L547 (definition of `is_monetize_by_tutor()`), L548-L550, and many call-sites: ~L1201, L1238, L1240, L2658, L2738, L2823, L3026, L6272, L6352, L6449, L7896 (search within file for `monetize_by`)
- `references/tutor-master/ecommerce/Ecommerce.php` — L63, L100 (checks using `is_monetize_by_tutor()` / constants)
- `references/tutor-master/includes/ecommerce-functions.php` — L152-L171 (reads monetization)
- `references/tutor-master/classes/Course.php` — multiple reads of `monetize_by` (e.g. L571, L609, L1282, L1522, L2300)
- `references/tutor-master/classes/Assets.php` — L224 (assets branch on native monetization)

Notes / recommendation

- The most critical spots for Course Bundle functionality are the Course Bundle files themselves (`Init.php`, `BundleModel.php`, `ManagePostMeta.php`, and `Integrations/WooCommerce.php`) and parts of `Enrollments.php` and `Course.php` that gate enrollment/purchase flows. Covering just the `Init.php` check is not sufficient — many runtime branches inside BundleModel, Enrollments, and enrollment/invoice code also check monetization and will behave differently for native vs WC vs PMPro.
- Best long-term approach: add a centralized helper (e.g. `tutor_utils()->is_monetize_by_pmpro()` or a small `Ecommerce` helper) and update the call sites above to accept PMPro where appropriate. Short-term you can intercept reads / shim specific spots (what we've been doing) but audit indicates you’ll need to touch ~15–30 locations for full parity.
- If you want, I can (A) produce a single patch that updates the Course Bundle–relevant files to accept PMPro (minimal, targeted), or (B) implement the centralized helper and sweep/update the call sites listed above (safer/cleaner). Which do you want next?
