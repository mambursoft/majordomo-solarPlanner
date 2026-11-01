# Safety

The module is safe-by-default: it computes and stores plans but does **not**
write to the inverter unless it is explicitly armed with a valid approval and a
verified device profile.

## Control states

`observe` → `shadow` → `approval_required` → `armed` → `applying` →
`verifying` → `active`, plus `degraded`, `blocked`, `manual_override`.

- `observe` / `shadow`: calculate only. No writes. This is the default.
- `armed`: writes are possible, but every precondition must pass first.
- `degraded` / `blocked`: a check failed; writing is refused.
- `manual_override`: the owner (or a detected foreign change) owns the TOU; the
  module will not overwrite it.

## Pre-write checks (all must pass)

1. mode is armed,
2. a valid, unexpired approval exists,
3. telemetry is fresh,
4. no BMS or inverter alarm,
5. the device profile is verified,
6. the current TOU can be read,
7. no foreign change detected,
8. the new schedule is valid (re-simulated),
9. every field is in the write allowlist (`tou_slots`, `grid_charge_enable`),
10. only then write.

## Post-write

- Read the settings back and compare with the expected result.
- **Absence of a TCP error is not success** — a readback mismatch marks the
  write unverified and moves to `degraded`.
- Record an audit entry.
- A partial write after a lost connection needs its own recovery; rollback is
  not assumed always possible or safe.
- Plan changes can be announced over TTS via `say($text, 1)` when enabled.

## Enabling / disabling control

- Enable shadow: set `CONTROL_MODE = shadow` (default). Plans are computed and
  stored; nothing is written.
- Enable armed: confirm every blocking parameter, verify the device profile,
  then set `CONTROL_MODE = armed` on the **Safety** tab. The UI blocks armed
  until the configuration unblocks it.
- Disable control at any time: set the mode back to `shadow` or `observe`, or
  use a manual override. To restore the previous inverter configuration, apply
  the private pre-write snapshot recorded by `PlanExecutor`.

## Manual control

The owner can pause automation, set a manual target, raise the emergency
reserve, pin the TOU for a period, return to shadow, forbid grid charge, and
approve a single plan without granting a standing write permission. Lease /
ownership and conflict detection prevent controller fights.

## Web interface safety

Uses MajorDoMo auth/authorization; settings change only via protected requests;
no dangerous apply over an open GET URL; CSRF protection; output escaping; config
validation; parameterized queries; masked secrets; no arbitrary URLs for
uncontrolled HTTP; SSRF guard on any custom endpoint; no arbitrary shell/eval in
configuration.
