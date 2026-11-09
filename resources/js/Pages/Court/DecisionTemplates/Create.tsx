import type { DecisionTemplateFormProps } from '@/court/types/decisions';
import TemplateForm from './TemplateForm';

/** The new-template page (Admin\DecisionTemplateController::create): the shared form with no template. */
export default function DecisionTemplateCreate(props: DecisionTemplateFormProps) {
    return <TemplateForm {...props} template={null} />;
}
