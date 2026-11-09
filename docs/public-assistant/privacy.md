# Addis One Help — privacy

- **Sent by the browser:** route name, path, and the QR token already in the
  URL. Never the DOM, form fields, card numbers, OTP codes, cookies or
  storage.
- **Kept:** the last `public_history_turns` turns in the guest session
  (opaque Laravel session id, redacted text, allow-listed blocks). No
  table, no visitor id, no profile, no analytics of message text.
  Retention = session lifetime (`NEEDS_POLICY_DECISION_PUBLIC_CHAT_RETENTION`).
- **Browser storage:** nothing. No localStorage, no history across tabs.
- **Sent to the AI provider (only if `public_content` is allowed outbound
  and a provider is configured):** published guidance passages, FAQ text,
  published service summaries, the scanned institution's public name and
  phone, the recent turns, the question. Never employee identity beyond the
  page's display name, never submissions, never anything internal.
- **Logged:** AI runs in `intelligence_ai_runs` with sizes and status, no
  input text; application errors through the normal error logger (redacted).
- **Inference:** none — no classification of visitors, no language
  detection beyond the site locale, no retention of IP beyond the rate
  limiter's cache window.
- **Contacts:** only the configured `general.support_*` values and an
  institution's `public_contact_phone`; employee numbers are never read.
