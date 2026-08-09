# Frontend planner module

The planner UI remains extractable from the NexA shell.

- core/ contains host-neutral state and formatting rules.
- api/ contains API client functions.
- views/ contains month, week, day, onboarding, diagnostic, and task views.
- components/ contains reusable task cards and controls.
- adapters/ connects navigation, theme, icons, and authentication to the host application.