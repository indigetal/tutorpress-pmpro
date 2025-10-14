System patterns for TutorPress — PMPro integration

Architecture:

- Standalone TutorPress addon that registers server-side integration hooks between TutorPress REST API and PMPro level management
- No Gutenberg UI changes needed (TutorPress already exposes PMPro UI when engine is selected)

Key technical decisions:

- Intercept TutorPress course settings saves to dynamically create/update PMPro levels
- Use PMPro's native `PMPro_Membership_Level` class and database functions for level CRUD
- Store bidirectional associations: course meta → level IDs and level meta → course ID

Data Flow Patterns:

1. **TutorPress Save** → REST API → Course Settings Controller → `update_post_meta()`
2. **Meta Update Hook** → PMPro Level Manager → Level Creation/Update
3. **Level Changes** → Update course meta with level associations

Data Storage:

- Course Meta: `_tutorpress_pmpro_levels` (array of level IDs for this course)
- Level Meta: `tutorpress_course_id` (source course ID for this auto-generated level)
- Namespacing: All meta keys prefixed with `tutorpress_` or `TUTORPRESS_PMPRO_`
