import type { DecisionFormProps } from '@/court/types/decisions';
import DecisionForm from './DecisionForm';

/** The edit-decision page (Admin\DecisionController::edit): the shared form seeded with the decision. */
export default function DecisionEdit(props: DecisionFormProps) {
    return <DecisionForm {...props} />;
}
