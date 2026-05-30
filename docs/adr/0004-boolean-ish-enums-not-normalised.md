# 0004. Model ACF's serialised boolean forms instead of normalising them

## Context

ACF serialises boolean flags inconsistently across export contexts:

- taxonomy bool flags as integers `0` / `1`,
- CPT and field-group flags often as real JSON booleans `true` / `false`,
- permalink-rewrite numeric flags as **strings** `"0"` / `"1"`.

A schema validates the bytes on disk, not an idealised model. Two tempting
shortcuts both fail:

- Declaring these `type: boolean` rejects the integer- and string-encoded forms
  ACF actually writes.
- Declaring them `type: integer` accepts `2`, `-1`, `42` — values that are never
  valid for a flag.

## Decision

Model the **actual serialised form per context**, and use a closed enum for
"boolean-ish" flags rather than a broad scalar type:

```json
{ "enum": [true, false, 0, 1] }
```

Pick the enum members that match what ACF emits in that specific context
(add `"0"` / `"1"` where the value ships as a string). Do not normalise the
different encodings into one canonical form — the schema describes ACF's output,
it doesn't reshape it.

## Consequences

- Real exports validate across all three encodings; junk values like `2` or
  `-1` are still rejected.
- Each flag must be modelled in the context it appears in — there's no single
  shared "boolean" definition to reuse blindly, so adding a flag means checking
  how ACF serialises it for that root type.
- The schemas faithfully mirror ACF's quirks, which means they also inherit
  them: if ACF changes an encoding in a future version, the affected enum must
  be updated (and `SnapshotTest` against a live install is what surfaces it).
