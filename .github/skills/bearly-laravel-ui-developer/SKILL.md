---
name: bearly-laravel-ui-developer
description: "Use when designing, improving, or coding Bearly e-commerce frontend pages in this Laravel project. Follow the latest repo state, Blade/Tailwind/Vite patterns, preserve working functionality, and keep frontend-only changes scoped to UI, styling, and client-side behavior."
---

# Bearly Laravel UI Developer

Use this skill when working on Bearly frontend pages, layouts, dashboards, forms, product screens, admin views, seller panels, courier flows, or any UI change in this Laravel project.

## Goal

Design, refine, and implement professional e-commerce and ERP-style UI pages that match the current Bearly product direction while preserving existing routes, controllers, Blade variables, form actions, authentication, and working functionality.

## Must-Follow Rules

- Use the current Bearly GitHub repository state and the branch specified by the user.
- Inspect the latest project files before making changes.
- Never assume an old file, prior version, or stale view is still current.
- Use Laravel Blade, Tailwind CSS, JavaScript, and the technologies already installed in the project.
- Focus on frontend development only unless the user explicitly requests backend or database work.
- Never create migrations, models, tables, seeders, or database logic for frontend-only tasks.
- Preserve existing routes, controllers, Blade variables, form actions, authentication, and working functionality.
- Do not rename or remove existing files without permission.
- Keep the UI consistent with the existing Bearly dashboard and ERP-style components.
- Design pages to look like a professional modern e-commerce seller platform.
- Use a clean, modern, formal, and organized layout.
- Use the existing Bearly color palette, typography, logo, sidebar, header, cards, buttons, borders, and spacing.
- Do not use bear images, bear icons, cartoon graphics, or childish decorations unless the user explicitly requests them.
- Use simple professional icons and subtle visual effects.
- Avoid excessive gradients, animations, oversized components, and crowded layouts.
- Allow vertical scrolling when a page contains a lot of information.
- Make layouts responsive for desktop, tablet, and mobile.
- Reuse existing components and styles before creating new ones.
- Use provided screenshots and mockups as layout references, but adapt them to Bearly’s existing visual identity.
- Do not copy another website exactly; create an original implementation inspired by professional e-commerce and ERP interfaces.
- For seller pages, prioritize clear data hierarchy, readable tables, practical filters, status badges, summary cards, and useful actions.
- Use realistic frontend-only sample data when a page needs populated and empty-state previews.
- Clearly separate sample frontend data from real backend data.
- Before editing, identify the exact files that need changes.
- After editing, check for Blade syntax errors, missing assets, broken imports, responsive problems, and JavaScript console errors.
- Run available frontend build or validation commands when possible.
- Report all files added or modified.
- Provide complete replacement files when asked for full code.
- Include clear instructions showing where each file should be placed.
- Do not push or commit changes unless explicitly asked.

## Workflow

### 1. Confirm scope and repo state

- Check the current branch and repository status before changing files.
- Review the latest relevant routes, controllers, and Blade views.
- Prefer the latest files over older snapshots or prior implementation notes.
- Confirm whether the task is strictly frontend-only.

### 2. Locate the exact files to change

- Search the route definitions and controller methods for the target page.
- Inspect the relevant Blade view, layout, and stylesheet files.
- Check whether a page already has a reusable pattern or a similar screen in another module.
- Limit the edit scope to only the files necessary for the page or shared UI update.

### 3. Read before editing

- Read the actual route, Blade template, shared layouts, and relevant CSS/JS files.
- Understand the variables already passed to the view.
- Preserve form actions, route names, and view data shape.
- Reuse existing class names, card patterns, sidebar structure, table styles, badge styles, and spacing conventions before creating new ones.

### 4. Design decisions

- Match the existing Bearly dashboard and ERP system style.
- Favor structured, modern, professional layouts over decorative-heavy patterns.
- Keep content hierarchy readable and business-oriented.
- For seller flows, emphasize summary metrics, action buttons, filters, tables, and status states.
- For admin/courier screens, keep information density organized and scannable.
- Use front-end sample data only when the page needs a populated preview or empty state.
- Distinguish clearly between sample UI placeholders and real data fields.

### 5. Implement the UI

- Use Laravel Blade templates, shared layout wrappers, and existing CSS architecture where possible.
- Add or adjust Tailwind utility patterns in the current style system.
- Use native JavaScript only where already consistent with the project; avoid introducing unnecessary frameworks or heavy dependencies.
- Keep the page responsive and scroll-friendly for larger inventories and dashboard data.
- Maintain valid HTML structure and Blade syntax.

### 6. Validate after editing

- Check the Blade file for syntax issues.
- Verify referenced CSS and JS assets still exist and are imported correctly.
- Confirm the view still loads with the current routes and shared layout.
- Check for responsive layout issues, overlapping content, broken spacing, or missing states.
- Run available frontend validation or build commands such as Vite or project-specific checks when possible.
- If there are console or runtime issues visible in the browser or build output, fix them before finishing.

### 7. Finish with a clear report

- List all files added or modified.
- Summarize what was changed and how it aligns with Bearly’s existing visual direction.
- State any assumptions made for sample data or frontend-only placeholders.
- If a request requires full code replacement, provide the complete file content in the requested location.

## Quality Criteria

The work is complete only when all of the following are true:

- The page matches the Bearly visual identity and existing ERP/dashboard patterns.
- The implementation is frontend-only and does not introduce backend or database changes without explicit permission.
- Existing routes, controllers, and Blade variables still work.
- The design is responsive for desktop, tablet, and mobile.
- Layout hierarchy is organized, readable, and professional.
- Sample data is clearly marked as frontend-only.
- The page builds or validates without obvious asset or syntax problems.
- The change scope is minimal and directly related to the task.

## Decision Points

### If the user asks for a new or redesigned page

- Identify the route and view involved.
- Reuse existing layout patterns before creating new structures.
- Keep the design consistent with Bearly’s established admin/seller/courier look.
- Add realistic sample data only when necessary for preview.

### If the user asks for a backend change

- Do not proceed as a frontend-only task.
- Explain that the request falls outside this skill’s scope unless the user explicitly asks for backend work.

### If the user asks for full code replacement

- Provide the complete file or files as needed.
- Keep the file in the correct project location.
- Preserve any required route and Blade variable contracts.

### If the user provides a screenshot or mockup

- Use it as visual inspiration, not a literal copy.
- Adapt it to Bearly’s current branding, spacing, and layout system.
- Keep it original and polished rather than duplicating another site.

## Example Prompts

- “Improve the seller product listing page to feel more like a polished e-commerce dashboard.”
- “Redo the admin registrations page with a cleaner ERP layout and better status cards.”
- “Create a professional courier dashboard with summary metrics and activity sections.”
- “Update this seller store page to match a modern e-commerce admin UI without changing backend logic.”
- “Give this page a cleaner Bearly look and improve mobile responsiveness.”

## Output Expectations

When using this skill, return:

1. The exact files inspected and updated.
2. A concise explanation of the design decisions made.
3. Notes about any frontend-only sample data used.
4. Validation results, including any build or syntax checks run.
5. A summary of any remaining risks or follow-up items.

Do not push or commit changes unless the user explicitly requests it.
