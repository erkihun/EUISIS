# Grievance decisions and executive approval

`GrievanceDecisionService` owns drafts, revisions, review, voting, submission, approval, correction, rejection, finalization and issuance. Decisions belong to a case stage and use increasing version numbers. Returned/rejected content is revised through a new version; previous versions and approval actions remain available for history.

## Authority resolution

`GrievanceApproverResolver` chooses an effective approval rule by exact handler, handler type, category, decision type, organization and priority. Approval is resolved through **position → active employee assignment → active user with `grievance_decisions.approve`**, plus effective delegations. Role labels alone do not identify the executive.

A category can require approval even without a matching rule. Missing approver configuration then blocks progress rather than guessing the executive. Where no rule or category requires executive approval, the authorized stage lead can finalize directly under the configured review/quorum requirements.

The access service prevents preparer self-approval unless the explicit self-approval setting permits it. Delegation records restrict authority by time, position/holder and organization; the delegate still needs approval permission. An expired delegation must not confer ongoing approval authority.

## Workflow

1. Authorized handler prepares findings, legal basis, decision text and recommendations.
2. Internal review and configured quorum/voting requirements apply before submission/finalization.
3. Required executive authority approves, returns for correction with a comment, or rejects with a reason.
4. Correction creates a new decision version and resubmission preserves the prior version and its history.
5. Finalization assigns a decision reference. Issuing the associated signed official letter marks the decision issued and updates the case; finalization alone does not notify the employee of an issued decision.

Transitions lock the relevant rows and reject stale attempts. Approval actions store actor, action, timestamp, comments and delegation where applicable. Corrective actions are linked records; they do not replace the immutable issued outcome.

## NEEDS_DECISION

Define approval rules for every production handler/category, authorized positions and deputies, quorum and voting treatment, dissent handling, rejection versus correction grounds, and emergency self-approval policy. Seeded/default settings are implementation defaults, not approval of these institutional rules.
