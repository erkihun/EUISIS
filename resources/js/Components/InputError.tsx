import { HTMLAttributes } from 'react';
import { FieldError } from '@euisis/ui';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <FieldError
            {...props}
            className={className}
        >
            {message}
        </FieldError>
    ) : null;
}
