# NexA AI Frontend UI/UX Audit

**Audit date:** 2026-08-07
**Scope:** Public chat interface, login, admin login, responsive CSS, accessibility, interaction design, frontend architecture, performance, and visual consistency.
**Evidence:** Local rendered inspection at 1280x720, DOM accessibility inspection, source review, asset checks, and frontend syntax validation.

**Post-hardening update:** 2026-08-08. Guest chat, exam selection, prompt chips, dark mode, mobile drawer behavior, login view switching, admin login, and self-hosted vendor assets were browser-checked on the clean local router at desktop and 390px mobile dimensions with no console errors. All inline HTML event attributes and executable inline scripts were removed from the shipped frontend.

## Executive verdict

**Current maturity: visually promising, locally verified release candidate; production polish remains.**

The product has a recognizable NexA identity: coral/red accent color, soft workspace surfaces, clear chat composer, useful study actions, and a polished authentication split-screen. The interface is substantially better than a generic chatbot shell.

It is not yet a premium, production-grade frontend because the visual system is layered on top of an older dark-first system instead of replacing it cleanly. That creates cascade conflicts, a non-functional theme toggle, duplicated responsive rules, inconsistent semantics, and avoidable maintenance cost.

### Scorecard

| Dimension | Score | Assessment |
|---|---:|---|
| Brand and visual direction | 8/10 | Strong coral identity, good logo treatment, clear product tone. |
| Chat information architecture | 7/10 | Familiar flow with good quick actions and a visible composer. |
| Layout composition | 6/10 | Attractive, but the fixed composer competes with the main welcome surface. |
| Interaction quality | 6/10 | Core controls exist; loading, modal, and keyboard behavior need refinement. |
| Accessibility | 4/10 | Several labels, dialog roles, focus, and zoom issues remain. |
| Responsive confidence | 5/10 | Breakpoints exist, but the CSS is duplicated and mobile behavior is not covered by visual regression tests. |
| Frontend maintainability | 4/10 | Large monolithic controller, inline event handlers, and overlapping style layers. |
| Performance | 5/10 | Small runtime dependency footprint, but duplicate assets and unpinned CDN dependencies add cost and risk. |
| Admin UI quality | 5/10 | Functional and branded, but visually and structurally separate from the main design system. |

## What is already working

### Chat product experience

- The chat is immediately recognizable as the primary product action.
- The composer is prominent and has sensible affordances: attachment, voice, custom quiz, send, and stop-generation.
- Quick-start study prompts reduce blank-page anxiety.
- The quiz CTA gives the product a differentiated education workflow instead of presenting only a generic chat box.
- The profile drawer has a useful information hierarchy: usage, referral, bookmarks, settings, and sign out.
- The AI and user message bubbles have clear directional distinction.
- The interface includes reduced-motion CSS, safe-area handling, dynamic viewport units, loading/typing states, and a scroll-to-bottom affordance.
- The login page has a strong split-screen composition and communicates the product value before asking for credentials.
- The admin login page is restrained and appropriately separated from the student experience.

### Visual system

- The coral palette is coherent and more aligned with the desired NexZen/WATI-inspired direction than the original dark-first palette.
- Rounded surfaces, restrained shadows, and large whitespace give the app a calm study-workspace feel.
- The brand mark is consistently visible in the header, splash, authentication, and AI message identity.
- The typeface choices are appropriate for a modern education product and include Bengali support.

## P0 findings

### P0-1: Theme toggle is functionally broken

**Evidence**

- frontend/nexa-app.js:138-150 correctly changes html[data-theme] between dark and light.
- frontend/nexa-main.css:36-48 defines a light theme.
- frontend/nexa-main.css:1251-1271 adds an unscoped :root coral theme after the earlier theme definitions.
- The later unscoped :root rules win for both dark and light states.
- Rendered inspection showed data-theme="light" while --bg remained #fff8f7 and the header remained the same light palette.

**User impact**

The moon button communicates that the theme changed, but the interface does not visibly change. This is a trust-breaking interaction and makes the Settings > Dark Mode control misleading.

**Recommendation**

Choose one explicit theme contract:

- Keep coral as the only production theme and remove the theme controls completely; or
- Define scoped themes as [data-theme="dark"] and [data-theme="light"], move all coral tokens into the light/coral scope, and add a real dark palette.

The recommended product choice is to keep coral/light as the default and implement a deliberate dark mode only after the light system is stable. Never use a second unscoped :root block for a theme.

**Acceptance criteria**

- Theme toggle visibly changes background, surface, border, text, input, drawer, modal, and button colors.
- Header icon and drawer checkbox stay synchronized.
- Theme remains correct after reload.
- Contrast is tested in both themes.

## P1 findings

### P1-1: Fixed composer overlaps the main workspace on desktop

**Evidence**

- frontend/nexa-main.css:525-535 positions .input-footer-wrap fixed at the viewport bottom.
- frontend/nexa-main.css:340-348 and :1164-1166 compensate with artificial bottom space and desktop-specific margins.
- At 1280x720, the welcome card visibly continues behind the composer. The composer and quiz pill read as a floating overlay rather than part of a stable page layout.

**User impact**

The first-run surface feels clipped. On shorter laptop screens, the main content can appear hidden behind the input island. The user has to infer that the card continues below an overlay.

**Recommendation**

Use a three-row application shell:

1. Header.
2. Scrollable conversation region.
3. Composer region in normal layout flow.

Keep the composer visually elevated with a surface shadow, but do not use viewport-fixed positioning on desktop. Use fixed/sticky behavior only where mobile keyboard behavior requires it.

**Acceptance criteria**

- The welcome card is never obscured by the composer at 768px, 900px, and 1080px viewport heights.
- The last chat message can be fully read without manual scrolling past an overlay.
- The composer remains visible while the chat region scrolls.
- The keyboard-open state still works on mobile.

### P1-2: Stylesheet has two competing design systems

**Evidence**

- frontend/nexa-main.css:7-34 defines a dark-first system.
- frontend/nexa-main.css:36-48 defines a light system.
- frontend/nexa-main.css:1251 onward appends a second coral workspace system.
- The stylesheet repeats rules for .app-header, .chat-area, .welcome-state, .input-island, .send-btn, .profile-drawer, .quiz cards, and responsive states.

**User impact**

A small change can be overridden by a later rule without being obvious. Dark mode, component states, and responsive behavior become difficult to reason about. This is the main source of visual drift.

**Recommendation**

Refactor nexa-main.css into a small token layer and component layers:

- tokens.css: color, type, spacing, radius, shadow, motion.
- base.css: reset, typography, focus, utilities.
- shell.css: app, header, main, chat, composer.
- components.css: bubbles, chips, drawers, modals, quiz, states.
- responsive.css: one media-query section per breakpoint.

If a build step is intentionally avoided, concatenate the same files into one release CSS file without duplicate selectors.

### P1-3: Accessibility semantics are incomplete

**Evidence**

DOM inspection found:

- Quiz and report overlays are visual cards without role="dialog", aria-modal="true", or aria-labelledby.
- The profile drawer is an aside without an explicit dialog/navigation contract or focus trap.
- quizTopicInput and reportText have visible labels that are not associated with their controls through for/id or aria-labelledby.
- The hidden PDF input has no accessible label.
- Choice buttons for quiz count and difficulty expose visual active classes but no aria-pressed state.
- The document contains two h1 elements: a hidden SEO h1 and the visible product h1.
- Buttons generally omit type="button"; this is safe today because there is no wrapping form, but it is fragile.
- The file picker uses a label as the visible control instead of a keyboard-first button pattern.

**Recommendation**

Create a shared accessibility contract:

- Add dialog roles, modal labels, aria-hidden state, and focus restoration.
- Trap focus inside open dialogs and drawers; close on Escape.
- Associate every label with a control.
- Add aria-pressed to toggleable quiz controls.
- Add explicit type="button" to non-submit buttons.
- Use a visually hidden file input plus a real button with a click proxy.
- Keep one visible h1 and make SEO copy supplementary rather than a duplicate heading.

### P1-4: Inline event handlers are still widespread

**Evidence**

frontend/nexa-app.js contains inline handlers in generated markup for attachment removal, exam selection, quiz options, weak-topic actions, bookmarks, copy/save actions, and modal close actions. frontend/index.html also contains an inline handler for Change Target Exam. The admin pages use inline onclick handlers extensively.

**User impact**

Inline handlers make keyboard testing, event ownership, escaping, and future componentization harder. Several handlers interpolate user/content-derived values into HTML strings. Even when escaping is currently adequate, this is a fragile boundary.

**Recommendation**

Use event delegation with data attributes:

- Render data-action and data-index attributes.
- Attach one listener per stable root.
- Resolve the action from the closest button.
- Keep content values in DOM properties or state maps rather than JavaScript inside HTML attributes.

This also makes the frontend easier to test without relying on window-global functions.

### P1-5: Login page prevents zoom

**Evidence**

frontend/login.html:4 uses maximum-scale=1.0 and user-scalable=no.

**User impact**

Users with low vision cannot reliably enlarge the login form. This is an avoidable accessibility regression, especially on a mobile-first student product.

**Recommendation**

Remove user-scalable=no and maximum-scale=1.0. Use responsive typography and layout to control the page rather than disabling browser zoom.

### P1-6: Theme metadata does not match the coral UI

**Evidence**

- frontend/index.html:7 sets theme-color to #08090f.
- frontend/manifest.json uses background_color #fff5f5 and theme_color #E8242B.
- The rendered coral workspace uses #fff8f7 and #E8242B.

**User impact**

On mobile/PWA surfaces, the browser chrome and splash color can appear dark or visibly different from the actual app. This makes the product feel less finished.

**Recommendation**

Use one brand source of truth for theme metadata. Align HTML theme-color, manifest theme_color, manifest background_color, splash background, and the production light/coral tokens.

### P1-7: UI copy and symbol conventions are inconsistent

**Evidence**

The chat surface mixes text arrows such as ->, emoji-based controls, uppercase labels, and icon-only actions. frontend/index.html:333 contains the literal text Report a Problem ??. Several surfaces use labels such as CLEAR, REPORT, QZ, and QUIZ with different visual weights.

**User impact**

The product feels assembled from multiple iterations rather than governed by one interaction language. The report modal in particular exposes unfinished copy.

**Recommendation**

Create a content and icon convention:

- Use one arrow icon system rather than ASCII arrows.
- Use sentence case for actions.
- Use real icons with accessible names for compact controls.
- Replace unfinished placeholders and doubled question marks.
- Keep labels explicit: Create quiz, Clear bookmarks, Report a problem, Start mock quiz.

### P1-8: Modal and drawer state management needs a shared primitive

**Evidence**

The frontend has profile drawer, quiz setup, report, leaderboard, splash, toast, and attachment states. They use separate classes and direct DOM operations. Modal cards do not share a semantic/focus contract.

**User impact**

Escape behavior, scroll locking, focus restoration, and screen-reader announcement behavior can drift between surfaces.

**Recommendation**

Implement one modal/drawer controller with:

- open/close lifecycle,
- overlay click policy,
- Escape handling,
- focus return target,
- body scroll lock,
- aria-hidden management,
- reduced-motion support.

### P1-9: The frontend controller is too large for safe iteration

**Evidence**

- frontend/nexa-app.js is approximately 1,685 lines.
- It contains 65 named functions, 28 innerHTML writes, 18 inline onclick occurrences, and 20 window references.
- It owns boot, theme, profile, auth, history, welcome state, API transport, streaming, quizzes, markdown, bookmarks, leaderboard, viewport handling, and device behavior.

**User impact**

Visual changes require navigating product logic, transport logic, and DOM generation in one file. Regression risk will increase as more features are added.

**Recommendation**

Split after the current UI contract is stabilized:

- app-shell.js
- chat-state.js
- chat-renderer.js
- transport.js
- quiz-ui.js
- profile-drawer.js
- modal-controller.js
- accessibility.js
- theme.js

Keep the existing PHP API contract unchanged during this refactor.

## P2 findings

### P2-1: Exact duplicate image assets

frontend/logo.png, logo-icon.png, icon-192.png, and icon-512.png have the same SHA-256 hash and each is 273,911 bytes.

**Impact**

The release carries approximately 1.1 MB of duplicate binary assets. The names imply different purposes and dimensions, but the files are byte-identical.

**Recommendation**

Keep one source logo and generate correctly sized 192px and 512px PWA icons. Use logo-icon.png only if it is intentionally a separately cropped icon. Verify actual dimensions before replacing manifest references.

### P2-2: Unused widget.js needs an ownership decision

widget.js is present in the release set but is not referenced by the canonical HTML pages.

**Recommendation**

Either document it as an externally embedded widget package with a consumer and test page, or move it out of the default application release. Do not carry unowned frontend code indefinitely.

### P2-3: External dependencies have no integrity policy

Resolved 2026-08-08: the canonical app and admin runtime libraries plus Google font files are self-hosted under frontend/vendor. Google Sign-In remains an intentional external identity-provider dependency.

**Recommendation**

Prefer self-hosting critical libraries or introduce a deliberate policy:

- pin exact versions,
- use SRI where supported,
- define a CSP that names approved origins,
- test degraded behavior when a CDN is unavailable.

### P2-4: No visual regression or interaction test suite

The current checks cover JavaScript/PHP syntax, but not:

- mobile layout,
- keyboard-only navigation,
- modal focus behavior,
- theme switching,
- composer overlap,
- quiz flow,
- login validation,
- attachment previews,
- provider error rendering.

**Recommendation**

Add a browser smoke suite with fixed viewport snapshots and assertions for the critical journeys.

### P2-5: Admin UI is not using the same component system

The admin pages use separate CSS tokens and inline handlers. The visual language is related, but spacing, controls, labels, and interaction patterns are not governed by the same primitives as the chat app.

**Recommendation**

Share tokens and primitives for button, field, card, modal, toast, focus ring, and status badge. Keep admin information density higher, but not visually unrelated.

### P2-6: Mobile keyboard behavior is custom and high-risk

nexa-app.js:1615 onward changes app height and transform using visualViewport. This is a reasonable mitigation, but it is sensitive to browser differences and can interact with the fixed composer.

**Recommendation**

Test iOS Safari, Android Chrome, installed PWA mode, landscape phones, and external keyboards. Prefer CSS dynamic viewport units and minimal JavaScript correction.

## UX journey assessment

### 1. First visit

**Strengths**

- Logo and product name establish identity.
- The welcome card has a clear study companion framing.
- Quick actions make the blank state actionable.

**Issues**

- The target exam currently renders as hello in the inspected guest state. This looks like test data and damages first-impression credibility.
- The welcome state is visually centered, but the fixed composer and quiz pill compete for attention.
- There is no short explanation of what happens after the user sends a question or how guest usage works.

**Target experience**

Show a concise target selection only on first visit, then present three high-value prompts and one primary action. Keep the composer in flow and make the study context persistent but quiet.

### 2. Sending a question

**Strengths**

- Enter-to-send and Shift+Enter behavior is familiar.
- Streaming state includes a stop action.
- Attachment, voice, and quiz affordances are close to the input.

**Issues**

- The input action row is icon-dense for a new user.
- The attachment control supports image and PDF but presents one generic image icon.
- The composer does not visibly explain supported file size/type or voice permission behavior.
- Error and provider-unavailable states should be designed as product states, not only toast messages.

### 3. Reading an AI response

**Strengths**

- Markdown, math, code, copy, save, reactions, and quizzes create strong study utility.
- AI identity is visually distinct.

**Issues**

- Generated actions are attached through inline handlers.
- Markdown content needs consistent heading spacing and Bengali/English line-height tuning.
- Copy/save actions should have visible success state and accessible live announcement.
- Long answers need a stronger reading rhythm: source/context, answer, examples, and next action.

### 4. Quiz flow

**Strengths**

- Quiz mode is clearly differentiated.
- Count and difficulty selection is simple.
- Weak-topic continuation is a strong educational loop.

**Issues**

- The active choice state is primarily visual.
- Quiz setup and report use similar card primitives but lack shared semantics.
- The prominent quiz banner competes with the composer and welcome content.
- The first quiz action should explain estimated time and number of questions.

### 5. Profile and retention

**Strengths**

- Usage, streak, bookmarks, referral, and sign-out are logically grouped.
- The drawer is a sensible information architecture for a chat-first app.

**Issues**

- The profile drawer is overloaded for a first release.
- Clear Cache, leaderboard, target exam, report, bookmarks, and authentication all compete in one vertical list.
- The drawer needs focus containment, a clear title, and better grouping into Account, Study, and Support.

## Recommended design direction

### Keep

- Coral/red as the primary brand.
- White or near-white workspace surfaces.
- Soft gradients and restrained shadow.
- Large, calm welcome hierarchy.
- A single prominent composer.
- One profile drawer for lightweight account/study controls.
- Quiz as a secondary but visible product differentiator.

### Change

- Remove the dark-first CSS layer or make it a fully scoped theme.
- Make the app shell flow-based on desktop.
- Reduce decorative effects around the main task.
- Replace text/emoji UI conventions with one icon and label system.
- Convert the welcome card, composer, chips, cards, and modals into shared components.
- Use explicit success, error, empty, loading, and offline states.

## Recommended implementation order

### Sprint 1: Correctness and trust

1. Fix the theme implementation or remove the broken theme controls.
2. Move the composer into the desktop layout flow.
3. Replace unfinished copy and ASCII arrow conventions.
4. Align theme metadata and manifest colors.
5. Remove login zoom restrictions.
6. Add dialog roles, labels, Escape handling, and focus restoration.

### Sprint 2: System cleanup

1. Consolidate nexa-main.css into tokens, base, shell, components, and responsive layers.
2. Introduce shared button, field, card, drawer, modal, toast, and focus primitives.
3. Replace inline event handlers with delegated actions.
4. Decide the ownership of widget.js and duplicate logo assets.
5. Move repeated inline styles into component classes.

### Sprint 3: Quality and polish

1. Split nexa-app.js by responsibility.
2. Add fixed viewport browser tests for desktop, tablet, mobile, and short landscape.
3. Add keyboard and screen-reader acceptance checks.
4. Add visual snapshots for guest home, active chat, quiz setup, profile drawer, login, and admin login.
5. Maintain the self-hosted vendor manifest and finish strict CSP enforcement after inline-style cleanup.
6. Tune typography and spacing using real Bengali and English content.

## Definition of premium frontend readiness

The frontend can be considered premium-ready when:

- Theme controls either work visibly or do not exist.
- The composer never covers chat content.
- Desktop, tablet, mobile, and landscape layouts have fixed viewport tests.
- Every dialog traps focus, closes predictably, and announces its title.
- Every input has a programmatic label.
- Quiz selections expose state to assistive technology.
- No application behavior depends on inline event-handler strings.
- The main CSS has one source of truth for tokens and one source of truth for component states.
- The first-run target exam is real user state, never test text such as hello.
- No unfinished symbols or placeholder copy appear in any visible surface.
- Critical third-party assets have a documented integrity/fallback strategy.
- The main controller is modular enough for independent UI work.

## Final assessment

NexA AI has a credible premium visual foundation, especially in the coral chat shell and split-screen login. The next investment should be system cleanup and interaction correctness rather than more decorative styling.

The highest-value fixes are:

1. Make theme behavior truthful.
2. Resolve the composer/content overlap.
3. Establish real modal and form accessibility.
4. Consolidate the CSS cascade.
5. Replace inline handlers and large string-rendered UI boundaries.
6. Add responsive and keyboard regression coverage.

Once those are complete, the existing brand direction can support a polished, trustworthy education product without requiring a framework migration.
