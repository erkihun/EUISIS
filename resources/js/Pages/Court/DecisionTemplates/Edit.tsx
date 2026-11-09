import type { DecisionTemplateFormProps } from '@/court/types/decisions';
import TemplateForm from './TemplateForm';

/** The edit-template page (Admin\DecisionTemplateController::edit): the shared form seeded with the template. */
export default function DecisionTemplateEdit(props: DecisionTemplateFormProps) {
    return <TemplateForm {...props} />;
}
