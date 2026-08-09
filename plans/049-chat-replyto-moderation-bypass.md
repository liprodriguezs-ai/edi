# Plan 049: Redact hidden message bodies inside the `replyTo` relation (close moderation bypass)

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md`.
>
> **Drift check (run first)**:
> `git -C backend diff --stat 8117e66..HEAD -- Modules/Chat/app/Http/Controllers/ChatMessageController.php Modules/Chat/app/Models/ChatMessage.php`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch, treat
> it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: security
- **Planned at**: backend `8117e66`, 2026-07-01

## Why this matters

Chat messages hidden by moderation are supposed to be invisible to non-moderators
— the controller carefully replaces the body with a placeholder before serializing.
But the redaction only touches the top-level messages in the response; it never
recurses into the `replyTo` relation. `replyTo` is an allow-listed include for the
resident-facing endpoint, and it is a self-reference to `ChatMessage`, so it carries
the same `body`/`body_html` fields. Any authenticated resident can therefore read the
full text of a moderated-away message by requesting `include=replyTo` on a message
that quoted it. This defeats the entire "hidden, never shown to non-moderators"
invariant (spec §13.4.4) with a single query parameter — no special client needed.

## Current state

- `backend/Modules/Chat/app/Http/Controllers/ChatMessageController.php`
  — resident-facing Orion controller for `chat_message`.
  - `includes()` (lines 27–30) allow-lists `replyTo` (and `reactions`, `media`):
    ```php
    public function includes(): array
    {
        return ['channel', 'user', 'replyTo', 'reactions', 'media'];
    }
    ```
  - `redactHiddenBodies()` (lines 260–272) only iterates the top-level entities:
    ```php
    private function redactHiddenBodies(iterable $messages): void
    {
        if ($this->callerCanModerate()) {
            return;
        }

        foreach ($messages as $message) {
            if ($message instanceof ChatMessage && $message->hidden) {
                $message->body = self::HIDDEN_BODY_PLACEHOLDER;
                $message->body_html = null;
            }
        }
    }
    ```
  - `callerCanModerate()` (lines 274–279): `tokenCan('update.chat_message') || tokenCan('*')`.
  - The placeholder constant is `HIDDEN_BODY_PLACEHOLDER = '[Mensaje ocultado por moderación]'` (line 21).
  - This method is invoked from the Orion `afterShow`/`afterIndex`/`afterSearch` hooks (search the file for `redactHiddenBodies(` to confirm the exact call sites before editing).
- `backend/Modules/Chat/app/Models/ChatMessage.php` — `replyTo()` (≈ lines 58–61) is a `belongsTo(ChatMessage::class, 'reply_to_id')` self-reference; `hidden` is a boolean column; `body`/`body_html` are the sensitive fields.

Repo conventions: keep the fix inside the controller's existing redaction path (do
not add a global scope or an accessor — the placeholder is a presentation concern the
controller already owns). Match the existing private-method style in the same file.

## Commands you will need

| Purpose | Command | Expected on success |
|---------|---------|---------------------|
| Lint (PHP) | `cd backend && vendor/bin/pint --dirty` | reformats only touched files, exit 0 |
| Syntax | `cd backend && php -l Modules/Chat/app/Http/Controllers/ChatMessageController.php` | `No syntax errors detected` |
| Test (this module, isolated) | `cd backend && php artisan test --filter=ChatMessage` | all pass, incl. the new test |

> Backend tests run on HOST php against the shared Postgres `testing` DB. Run only
> ONE test process at a time (see repo CLAUDE.md — concurrent `migrate:fresh` on the
> shared DB produces non-deterministic `relation ... does not exist` failures). Use
> `php artisan test` (host), NOT sail.

## Scope

**In scope**:
- `backend/Modules/Chat/app/Http/Controllers/ChatMessageController.php` (edit `redactHiddenBodies`)
- `backend/Modules/Chat/tests/Feature/` (add or extend a moderation-visibility test — see Test plan; if the module tests are not yet wired into `phpunit.xml`, see plan 063, but you can still run this file by path filter)

**Out of scope** (do NOT touch):
- The `callerCanModerate()` gate and the moderator path — moderators are supposed to see everything.
- `includes()` — do NOT remove `replyTo`; residents legitimately need reply context for non-hidden parents. The fix is redaction, not removal.
- `ChatHtmlSanitizer` / XSS handling — unrelated and already correct.

## Steps

### Step 1: Recurse redaction into `replyTo` (and any loaded message-shaped relation)

In `redactHiddenBodies`, after redacting each top-level `$message`, also redact its
loaded `replyTo` when hidden. Extract the per-message redaction into a small private
helper so the top-level loop and the nested `replyTo` share one code path:

```php
private function redactHiddenBodies(iterable $messages): void
{
    if ($this->callerCanModerate()) {
        return;
    }

    foreach ($messages as $message) {
        if (! $message instanceof ChatMessage) {
            continue;
        }

        $this->redactIfHidden($message);

        // replyTo is an allow-listed include and a self-reference to ChatMessage,
        // so a hidden parent's body would otherwise leak through it.
        if ($message->relationLoaded('replyTo') && $message->replyTo instanceof ChatMessage) {
            $this->redactIfHidden($message->replyTo);
        }
    }
}

private function redactIfHidden(ChatMessage $message): void
{
    if ($message->hidden) {
        $message->body = self::HIDDEN_BODY_PLACEHOLDER;
        $message->body_html = null;
    }
}
```

**Verify**: `cd backend && php -l Modules/Chat/app/Http/Controllers/ChatMessageController.php` → `No syntax errors detected`.

### Step 2: Add a regression test

Add a feature test (model it after the existing Chat access/visibility tests in
`backend/Modules/Chat/tests/Feature/` — read one first for the tenant/user setup
pattern) that:
1. Creates a channel with two messages where message B `reply_to_id` = message A.
2. Hides message A (set `hidden = true`).
3. As a NON-moderator member, calls `POST /api/chat_message/search` (or the show
   route) with `include=replyTo` filtered to message B.
4. Asserts the response's `replyTo.body` equals the placeholder and `replyTo.body_html`
   is null — NOT the real text.
5. A second assertion: as a moderator (`update.chat_message`), the real body IS returned.

**Verify**: `cd backend && php artisan test --filter=ChatMessage` → all pass, including the new test. Confirm the new test FAILS if you temporarily revert Step 1 (proves it catches the bug).

### Step 3: Format

**Verify**: `cd backend && vendor/bin/pint --dirty` → exit 0, only the two touched files reformatted.

## Test plan

- New test in `backend/Modules/Chat/tests/Feature/` (e.g. `ChatMessageModerationVisibilityTest.php`), following the setup pattern of the existing Chat feature tests.
- Cases: (a) non-moderator + `include=replyTo` on a message quoting a hidden one → placeholder; (b) moderator → real body; (c) sanity: non-hidden replyTo parent is returned normally.
- Verification: `php artisan test --filter=ChatMessage` → all green.

## Done criteria

- [ ] `php -l` clean on the controller
- [ ] `php artisan test --filter=ChatMessage` passes, new moderation-visibility test included and green
- [ ] The new test fails when Step 1 is reverted (confirms it guards the bug)
- [ ] `vendor/bin/pint --dirty` exit 0
- [ ] No files outside the in-scope list modified (`git -C backend status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report (do not improvise) if:
- The `redactHiddenBodies` code no longer matches the excerpt above (drift).
- `reactions` or `media` turn out to also carry message bodies that leak (they
  shouldn't — `media` is images, `reactions` are emoji) — if you find another
  content-bearing include on a hidden message, report it rather than expanding scope.
- The test cannot run because module tests aren't discovered AND a path filter also
  fails — report; do not rewire `phpunit.xml` here (that's plan 063).

## Maintenance notes

- If a future include adds another self-referencing or message-shaped relation
  (e.g. a "forwarded from" pointer), it must be added to the redaction recursion too.
- Reviewer should confirm the nested redaction runs for BOTH the paginated search
  path and the single `show` path (both call `redactHiddenBodies`).
- Deferred: a2/a5 did not find `media` leaking hidden content, but if attachment
  captions are ever added to hidden messages, revisit.
