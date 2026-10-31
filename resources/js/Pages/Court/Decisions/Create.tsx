import type { DecisionFormProps } from '@/court/types/decisions';
import DecisionForm from './DecisionForm';

/** The new-decision page (Admin\DecisionController::create): the shared form with no decision. */
export default function DecisionCreate(props: DecisionFormProps) {
    return <DecisionForm {...props} decision={null} />;
}
