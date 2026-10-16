# Product
<!-- impeccable:product-schema 1 -->

## Platform
web

## Users
Two groups use the same panel, and the interface is built for the first one by default:

- **Server owners new to hosting.** People who run one to a handful of game servers (Minecraft, Rust, voice servers and similar) for friends or a community. They start, stop and restart servers, read the console when something goes wrong, edit a config file, and take backups. They should never need to learn how Wings, nodes or allocations work.
- **Power users and administrators.** People who manage many servers, or the panel itself. They want density, keyboard shortcuts and a command palette, and they move between servers dozens of times a day. Those affordances are opt-in, so they never get in a beginner's way.

## Product Purpose
NitroDiesel/panel is a fork of the Pterodactyl game server panel. It lets people run game servers in Docker containers on their own machines (through the Wings daemon) and manage them from a browser: power control, a live console, files, databases, schedules, backups, subusers and network ports. Administrators manage locations, nodes, servers, users and eggs (game templates) from a separate admin area.

Success means a first-time owner can find their server, see whether it is running, start it and read why it stopped, without help; and an experienced admin can do the same across dozens of servers without touching the mouse.

## Positioning
The fork keeps Pterodactyl's architecture and API, and sends small, upstream-mergeable improvements back. What it adds on top is a hardened deployment (non-root image, compose stack), security hardening, and an interface that explains itself to beginners while staying fast for experts.

## Operating Context
- People open the panel when something needs doing: a friend asks to join, a server crashed, a mod needs a config change, a backup before an update.
- The console is the most-watched screen: owners read crash logs and type commands there.
- Sessions happen at home, often in the evening, on desktop browsers and phones.
- Admins work through the separate admin area (server-rendered Blade pages, AdminLTE 2) as well as the client panel.

## Capabilities and Constraints
- Client panel: React 16 single-page app (TypeScript, Tailwind 3 through twin.macro and styled-components, easy-peasy state, SWR, Formik), under `resources/scripts`.
- Admin area: Blade views under `resources/views/admin`, built on AdminLTE 2 and Bootstrap 3 with jQuery.
- Changes must stay upstream-mergeable where possible. Shared improvements (structure, accessibility, states, opt-in power features) go to everyone; fork-only identity lives in theme values at the branding seams (PLAN.md 4.1), and the default look must stay identical to upstream when no fork theme is set.
- No new runtime dependencies without a reason recorded in PLAN.md.
- Terminology users see: server, console, files, backups, schedules, databases, users (subusers), network (allocations), startup, settings, activity.

## Brand Commitments
- The upstream product name is Pterodactyl; the footer credit and license attribution stay intact (MIT).
- The fork's name is NitroDiesel. No logo, colors or fonts have been decided for it.

## Evidence on Hand
- The working panel itself, runnable locally with seeded data.
- No customer quotes, usage statistics or brand assets exist; none may be invented.

## Product Principles
- Status first: whether each server is running, starting, stopping, offline, installing or suspended must be readable at a glance, in words as well as color.
- Beginners by default, experts by choice: density, shortcuts and the command palette are opt-in and never required.
- Plain language: name things by what the user controls (start the server, open the console), not by how the system works.
- Never lose a user's work: confirm destructive actions, keep input on errors, explain how to recover.

## Accessibility & Inclusion
WCAG 2.2 AA: keyboard access to every action, visible focus, contrast, pinch-zoom allowed, and status never carried by color alone.
