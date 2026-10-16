# Design System: NitroDiesel panel (line map)

Fork-only record of the visual decisions for the NitroDiesel theme. The upstream default look is unchanged; everything here is applied through the token and theme-stylesheet seams described in PLAN.md Phase 5. Directions explored and rejected: `branding/design/directions/`.

## 1. Visual theme and atmosphere
A working panel that reads like a transit line map. Each Wings node is a colored line; each server is a station on it, and the station marker says what state the server is in. The page is calm and orderly: a sign-white ground with a dark shell bar in light mode, a deep blue-black ground in dark mode (following the system setting). Density is "daily app" by default (one comfortable row per server) and "cockpit" when the user turns on the dense view. Motion is restrained: state changes and feedback only.

The world contributes four things and nothing else: the typeface, the palette, the density, and the signature move. Layout, navigation and controls stay standard web controls.

## 2. Color palette and roles
Contrast figures below were measured with the WCAG 2 relative-luminance formula (`branding/design/contrast.cjs`). Tokens are HSL channel triples on `:root`, consumed by Tailwind as `hsl(var(--token) / <alpha-value>)`. Roles map onto the existing ramp steps (`900` title and shell bars, `800` page ground and inputs, `700` surfaces, `600`/`500` fills and borders, `400` to `50` text from muted to strongest), so the light scheme inverts the gray ramp.

| Role | Light | Dark | Measured contrast (text roles, on ground / surface) |
|---|---|---|---|
| Ground (`gray-800`) | `#f2f4f7` | `#0f1720` | |
| Surface (`gray-700`) | `#ffffff` | `#17212c` | |
| Recess (`gray-900`) | `#e8ecf0` | `#0b1118` | |
| Fill (`gray-600`) | `#dfe4ea` | `#1f2b38` | |
| Border (`gray-500`) | `#7b8794` | `#607287` | non-text: 3.66 / 3.32 light; 3.30 / 3.66 dark (surface / ground) |
| Muted ink (`gray-400`) | `#56626e` | `#93a3b3` | 5.66 / 6.24 light; 6.99 / 6.30 dark; at least 4.88 on fill |
| Secondary ink (`gray-300`) | `#4a5866` | `#b3c0cc` | 6.62 / 7.29; 9.73 / 8.78 |
| Body ink (`gray-200`) | `#26323e` | `#d5dee6` | 11.85 / 13.06; 13.25 / 11.95 |
| Strong ink (`gray-100`) | `#15202b` | `#e6edf3` | 14.97 / 16.49; 15.27 / 13.77 |
| Shell bar (`shell`) | `#15202b` | `#0b1118` | shell text `#c8d2dc`: 10.77 |
| Action (`primary-500`, filled) | `#1d4ed8` | `#2f5fe3` | white label 6.70 light, 5.43 dark |
| Action text and links (`primary-400`) | `#1d4ed8` | `#7aa2ff` | 6.08 / 6.70; 7.25 / 6.54 |

State hues carry meaning only, always beside a word and a marker shape. Every state color measures at least 4.7:1 on ground, surface, recess and fill in both schemes (lowest: light starting on fill, 4.70):

| State | Light | Dark | Marker |
|---|---|---|---|
| Running | `#08704f` | `#4cc38a` | filled circle |
| Starting, stopping | `#a14a06` | `#f0a94a` | half-filled circle |
| Stopped, offline | `#56626e` | `#93a3b3` | ring |
| Installing, transferring, restoring | `#1d4ed8` | `#7aa2ff` | rounded square |
| Suspended, error | `#b91c1c` | `#ff8a80` | struck ring |

Node lines, assigned by node name, never reused as state colors: magenta `#a21caf` / `#e879f9`, cyan `#0e7490` / `#22d3ee`, olive `#5f6f0f` / `#bef264`, violet `#6d28d9` / `#a78bfa`, slate `#475569` / `#94a3b8` (at least 4.86:1 against the ground in both schemes; line color marks the line, node names stay in ink).

## 3. Typography rules
- **Interface:** Overpass (OFL, variable weight), self-hosted from `branding/fonts/`. Weights 400 body, 600 labels and buttons, 800 page titles. Numbers that change (addresses, CPU, memory, uptime) use `font-variant-numeric: tabular-nums`.
- **Terminal and code:** the panel's existing monospace stack; monospace is never used as decoration.
- **Scale (rem):** 0.8125 caption, 0.875 UI, 0.9375 body, 1 row title, 1.25 section, 1.75 page title. Page titles tracked `-0.02em`, line height 1.1. Sentence case everywhere; no all-caps labels.

## 4. Component stylings
- **Station list (server list):** one row per server on its node's line: marker, name with the game below it, address, state word with detail ("Running, 7 of 20 players"), and in dense view the load bands. Rows are separated by a 1 px rule, not boxed.
- **Buttons:** one filled action per view (`primary-500`); everything else is outlined on the surface. Destructive actions use the suspended red as text on an outlined button, filled only inside the confirmation dialog. 8 px radius; press feedback `scale(0.97)` at 100 ms.
- **Inputs:** label above, help below, error below in suspended red with an icon; 16 px text on phones.
- **Focus:** 2 px solid ring in the action color with a 2 px offset, on `:focus-visible` only.
- **Loading:** skeleton rows in the list's own shape; no centered spinners in content areas.
- **Empty states:** say what the screen is for and offer the first action.

## 5. Layout principles
- Top shell bar (brand, search or command palette entry, primary navigation, account). Content column max 1120 px with 24 px gutters, 16 px on phones.
- Server pages: server header (node line, name, state, power controls), then tabs, then the work area. The console takes the width; facts sit in a narrow list beside it on desktop and below it on phones.
- Group with space first, rules second; no nested cards; card radius 12 px maximum.
- Nothing scrolls horizontally at 320 px except the terminal and wide tables, which scroll inside themselves.

## 6. Motion and interaction
- Feedback and state only: button press 100 ms, popovers and menus 150 ms enter with `cubic-bezier(0.22, 1, 0.36, 1)`, exits faster than enters.
- The command palette and keyboard-triggered actions do not animate.
- A starting server's half marker is static; no looping animation anywhere in content.
- Everything honors `prefers-reduced-motion` (motion becomes an opacity change or nothing).

## 7. Anti-patterns (banned)
- Status shown by color alone, or by motion alone.
- All-caps micro labels, eyebrows above headings, middle-dot strings joining more than two facts.
- More than one filled button per view; gradients; glows; glass panels; drop shadows on things at rest.
- Unicode glyphs or emoji standing in for icons (one icon set per surface, `currentColor`).
- Invented data, customers or metrics in empty states or examples.
