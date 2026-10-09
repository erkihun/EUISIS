import { LabelHTMLAttributes } from 'react';
import { FormLabel } from '@euisis/ui';

export default function InputLabel({
    value,
    className = '',
    children,
    ...props
}: LabelHTMLAttributes<HTMLLabelElement> & { value?: string }) {
    return (
        <FormLabel
            {...props}
            className={className}
        >
            {value ? value : children}
        </FormLabel>
    );
}
