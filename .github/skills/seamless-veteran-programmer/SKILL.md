---
name: seamless-veteran-programmer
description: "Implement software changes with veteran-level judgment, clean architecture, safe refactoring, and production-ready verification. Use when asked to design, implement, review, or harden features with minimal supervision. Triggers: seasoned coding workflow, robust implementation, senior-level coding, defensive programming, regression-safe changes."
argument-hint: "Task outcome, constraints, and risk tolerance"
user-invocable: true
---

# Seamless Veteran Programmer

## Outcome
Produce robust, maintainable, and test-verified changes for this CDD-File-Tracking-System PHP codebase with minimal back-and-forth.

## Use When
- The user asks for implementation quality beyond a quick patch.
- The task includes refactoring, architecture choices, or risk-sensitive behavior.
- The request needs practical tradeoff decisions and explicit validation.
- The change spans CDD-File-Tracking-System layers such as controllers, services, models, views, and SQL migrations.

## Inputs
- Target outcome and acceptance criteria.
- Constraints: performance, compatibility, security, deadlines.
- Current code context and test surface.
- CDD-File-Tracking-System architectural boundaries:
	- Controllers orchestrate request flow and response mapping.
	- Services hold business rules and cross-model operations.
	- Models handle persistence concerns.
	- Views remain presentation-focused.

## Workflow
1. Frame the change
- Restate desired behavior and non-goals.
- Identify impact area: files, modules, and externally visible behavior.
- Choose smallest safe change set first.

2. Inspect before editing
- Read nearby code paths, related models, and existing tests.
- Identify invariants that must not change.
- Mark unknowns that could alter implementation strategy.
- Map CDD-File-Tracking-System touchpoints up front:
	- Routing to controller entry point.
	- Controller to service boundaries.
	- Service to model and database effects.
	- User-facing views or API outputs affected.

3. Choose implementation strategy
- If change is isolated: patch locally and preserve current abstractions.
- If design debt blocks correctness: perform focused refactor first, then feature change.
- If uncertainty is high: implement in reversible steps with checkpoints.
- Maintainability-first rule:
	- Prefer structure improvements that reduce future change cost, if they do not expand immediate risk beyond acceptable limits.

4. Implement with defensive defaults
- Keep public contracts stable unless explicitly requested.
- Add guardrails for null, malformed, or unauthorized input.
- Prefer clear naming and composable functions over clever shortcuts.
- CDD-File-Tracking-System-specific implementation guidance:
	- Keep permission and role checks explicit near decision points.
	- Keep database writes in service-level flows where business rules are centralized.
	- Preserve audit and notification side effects when existing behavior depends on them.

5. Validate behavior
- Run or update tests closest to the changed behavior.
- Add regression tests for previously fragile paths.
- Sanity-check edge cases and failure modes.
- CDD-File-Tracking-System validation sequence:
	- Run targeted workflow tests in tests/ related to changed feature paths.
	- Verify both successful and denied access paths.
	- Verify document lifecycle side effects (sharing, reviews, notifications) when relevant.

6. Review like a maintainer
- Verify readability, coupling, and future extension points.
- Ensure logs, errors, and return paths are actionable.
- Confirm no unrelated churn was introduced.
- Check migration safety if schema or SQL changed:
	- Forward migration is clear and idempotent where expected.
	- Existing data assumptions are preserved or explicitly handled.

7. Report outcomes
- Summarize what changed and why.
- Call out risks, follow-ups, and deferred improvements.
- Provide concise next-step options when useful.

## Decision Points
- Scope control: local fix vs focused refactor.
- Safety level: fast path vs checkpointed incremental path.
- Testing depth: targeted tests vs broader regression pass based on risk.
- Layer placement: controller convenience logic vs service-level business logic.
- Data change strategy: code-only change vs migration-backed evolution.

## Completion Checks
- Behavior matches requested outcome.
- Existing behavior outside scope remains intact.
- Tests for new behavior and regressions are present or executed.
- Code is understandable by a teammate reading it cold.
- Remaining risks and assumptions are explicitly stated.
- CDD-File-Tracking-System architecture remains coherent:
	- Controllers are not overloaded with business logic.
	- Services remain the primary home for reusable business rules.
	- Models and SQL interactions stay consistent with existing patterns.

## Failure Recovery
- If validation fails, isolate whether issue is logic, integration, or environment.
- Revert only the problematic step, keep proven changes.
- Re-run minimal reproducer before continuing.
