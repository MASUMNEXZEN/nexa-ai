# Portable study planner

The planner is a product module, not a second application.

## Boundary

- domain/ contains framework-independent scheduling and mastery rules.
- application/ will orchestrate profile, content, diagnostic, and plan use cases.
- contracts/ will define host-neutral content-pack and API shapes.
- importers/ will parse Markdown and Word files into validated import records.
- adapters/ will connect the module to NexA authentication, PDO, API controllers, and AI providers.

The planner must not depend on NexA branding, DOM selectors, payment logic, or a specific exam. A host integrates it by supplying a content pack and adapters.