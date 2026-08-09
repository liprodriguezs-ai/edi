# Announcements: designed email + SMS off + preview (2026-07-04)

`AnnouncementService` (Modules/Announcements) changes:
- **Email goes through `blade_email`** (was `resend_email` plain text). `channelsFor()` emits `'blade_email'`
  when priority important/urgent OR `channel_email`; `dispatchPublishedNotifications()` sets
  `$data['email_template']='announcement-published'` + `$data['email_context']=[...]`. Template
  `announcement-published.blade.php` authored via React Email `.tsx` → `pnpm copy-emails`. Slug already
  hyphenated → view `email::announcement-published`.
- **`email_context` shape** (all NON-NULL): `title`, `bodyText` (`mb_substr(strip_tags(content),0,600)`),
  `priorityLabel` (`priority?->name ?? 'Normal'`), `audienceLabel` (from humanizer), `announcementUrl`
  (`config('app.frontend_url').'/announcement'`). Do NOT add `emailBaseUrl` — BladeEmailChannel adds it.
- **SMS DISABLED (surgical, re-enablable)**: removed the `'sms'` branch from `channelsFor()` and the
  `channel_sms=true` force on URGENT in `publish()` (push still forced). `channel_sms` column, `SmsChannel`,
  dispatcher `sms` map all intact — re-add both spots to re-enable. Matrix now: normal=`['log']`,
  important/urgent=`['log','blade_email']`.
- **Audience humanizer** = `Modules\Announcements\Services\AnnouncementAudienceLabeler::label(string, ?array): string`
  (autowired). Always non-empty Spanish; tenant-safe. Rules: all→"Todo el condominio", board→"Solo la mesa
  directiva", with_vehicles→"Unidades con vehiculo", manual→"N unidades seleccionadas",
  single_unit→`unit.full_identifier`, by_floor→"Piso(s) ...", by_{type,tower,section,feature}→"Por ...: Name, Name".
- **Preview endpoint** `POST /announcement/preview` (BEFORE the Orion resource, `$customMiddleware`,
  authorize **`create.announcement`** — NOT read: residents/renters must not enumerate head-counts; fix B1):
  validates `target_type` in TARGET_TYPES + `target_selector` array → `audienceUserIdsFor()` → bare `{count}`
  (no data-wrapper). Counts RESIDENTS (people via unit_user, board members for 'board'), NOT units —
  differs from Collections' `charge-batch/preview` which counts units. `authorize()` → `tokenCan($ability)`.
- **`audienceUserIdsFor(string,?array)`** extracted from `audienceUserIds(Announcement)` (delegates) so
  preview works without a persisted row. Uses **`TargetResolver::resolveUnitIds($type,$selector)`** (fix B2)
  — id-only mirror of `resolve()` (pluck ids, no hydration); `resolve()` UNTOUCHED. Mirrors arm-for-arm
  incl. billability + by_feature-empty→all + single_unit-null→empty.
- Tests updated: `AnnouncementsHttpTest` priority matrix + 3 new; `AnnouncementsModuleTest::test_urgent_priority_forces_push_channel_only`;
  `NotificationChannelTest` C test; `ClusterLifecycleTest:710`. 43 Announcement tests pass.
