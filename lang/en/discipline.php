<?php

declare(strict_types=1);

// Employee Discipline — server-side messages (docs/employee-discipline.md).
// Page text lives in resources/js/i18n/{en,am}/discipline.ts.
return [
    'flash' => [
        'appeal_noticed' => 'The appeal notice was recorded. The penalty is not executed while the appeal is pending.',
        'appeal_record_copy' => 'Delivery of the certified record copy was recorded.',
        'appeal_filed' => 'The court filing was recorded.',
        'appeal_outcome' => 'The court outcome was recorded.',
        'appeal_implemented' => 'Implementation of the ruling was recorded.',
        'opened' => 'Disciplinary case opened as a draft.',
        'charge_added' => 'Charge recorded.',
        'panel_assigned' => 'Panel assigned.',
        'charged' => 'Charge filed. The investigation period has started.',
        'summons_served' => 'Summons service recorded.',
        'response_recorded' => 'Response recorded.',
        'objection_decided' => 'Preliminary objection decided.',
        'absentia' => 'The case will continue in the employee\'s absence.',
        'evidence_added' => 'Evidence added.',
        'witness_recorded' => 'Witness testimony recorded.',
        'recused' => 'Member removed from this case.',
        'final_statement' => 'Final statement recorded.',
        'extended' => 'Investigation period extended to two months.',
        'recommendation' => 'Recommendation adopted and submitted to the head of institution.',
        'decided' => 'Decision recorded.',
        'suspended' => 'Suspension recorded. The employee keeps every right and duty not tied to the suspension.',
        'suspension_lifted' => 'Suspension lifted.',
        'suspension_back_paid' => 'Payment of the withheld salary recorded.',
        'referred' => 'The charge was referred to the disciplinary committee. Assign the committee and file the charge to start the formal procedure.',
        'delivered' => 'Delivery recorded.',
        'discontinued' => 'Charge discontinued.',
        'terminated' => 'Case ended because the employee\'s service ended.',
        'closed' => 'Case closed.',
    ],

    'suspension_ended_with_case' => 'The case ended.',

    'errors' => [

        'appeal_pending' => 'The case cannot be closed while the Administrative Court has the appeal, or while its ruling for the employee is still to be carried out.',

        'nothing_to_appeal' => 'Only a delivered decision that imposes a penalty can be appealed.',

        'appeal_date_invalid' => 'The date must not be in the future, and must follow the earlier step of the appeal.',

        'appeal_exists' => 'An appeal against this decision is already recorded.',

        'record_copy_not_due' => 'No copy of the committee record was requested, or it was already given.',

        'no_pending_appeal' => 'There is no pending appeal against the current decision.',

        'suspension_in_force' => 'A suspension is already in force on this case.',
        'suspension_dates_invalid' => 'The dates do not fit: the end must not come before the start, and a lifting date must be on or after the start and not in the future.',
        'suspension_too_long' => 'A suspension from work and salary lasts at most three months — the last day is :date at the latest. Give an end date.',
        'no_suspension_in_force' => 'No suspension is in force on this case.',
        'no_back_pay_due' => 'No withheld salary is due for this suspension.',
        'refer_expedited_only' => 'Only an expedited inquiry is referred to the disciplinary committee; a formal case is already before it.',

        'nothing_to_implement' => 'There is no ruling for the employee waiting to be carried out.',
        'forbidden' => 'You are not allowed to perform this action on this disciplinary case.',
        'no_current_assignment' => 'The employee has no current active assignment.',
        'expedited_minor_only' => 'The expedited inquiry is only for minor violations.',
        'discontinued_same_matter' => 'A charge on this matter was discontinued and cannot be brought again.',
        'committee_invalid' => 'Choose an active disciplinary committee of the employee\'s institution.',
        'committee_composition' => 'The committee does not have the required composition.',
        'investigator_required' => 'Appoint at least one investigator other than the accused employee.',
        'charge_required' => 'Record at least one charge first.',
        'panel_required' => 'Assign the committee or investigator first.',
        'summons_formal_only' => 'A summons is served only in a formal committee case.',
        'not_in_future' => 'This date cannot be in the future.',
        'summons_notice_too_short' => 'The hearing must be at least 10 working days after the summons is served.',
        'record_closed' => 'The investigation record is closed.',
        'plea_every_charge' => 'Admit or deny every charge; silence is not an admission.',
        'absentia_not_yet' => 'The case can continue in absence only after the hearing date and any posting period have passed.',
        'no_open_objection' => 'There is no undecided preliminary objection.',
        'charge_not_in_case' => 'The charge does not belong to this case.',
        'integrity' => 'The stored file failed its integrity check.',
        'recusal_not_allowed' => 'This member cannot be removed from the case.',
        'replacement_invalid' => 'The replacement must be another employee who is not already seated and is not the accused.',
        'seat_not_eligible' => 'This person cannot sit on the case: they must be an active employee and not the accused.',
        'seat_other_institution' => 'Only an employee whose current post is in the institution of the case can sit on it.',
        'seat_disciplined' => 'This employee was disciplined in the last two years and cannot sit on a disciplinary case.',
        'statement_required' => 'Enter the statement or record that the employee declined.',
        'response_required' => 'Record the employee\'s response or the decision to continue in absence first.',
        'final_statement_required' => 'Give the employee the opportunity of a final statement first.',
        'factor_required' => 'Address every required factor.',
        'already_extended' => 'The investigation period cannot be extended.',
        'delivery_date_invalid' => 'The delivery date must be on or after the decision and not in the future.',
        'invalid_status' => 'This action is not available at the case\'s current stage.',
        'no_penalty_without_finding' => 'No penalty may be recorded when responsibility is not established.',
        'penalty_required' => 'Choose one of the penalties provided by the regulation.',
        'serious_penalty_needs_committee' => 'A serious penalty can be recommended only through the disciplinary committee.',
        'penalty_amount_range' => 'The amount is outside the limit set by the regulation.',
        'vote_invalid' => 'Votes can be cast only by seated committee members.',
        'quorum_not_met' => 'Quorum requires the chairperson and at least two other members.',
        'recommendation_not_carried' => 'The proposed recommendation did not carry the vote.',
        'dissent_reason_required' => 'Every dissenting member must state the reason.',
    ],
];
