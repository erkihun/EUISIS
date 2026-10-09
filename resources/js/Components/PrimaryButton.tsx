import Button, { type ButtonProps } from './Button';

/** @deprecated Use Button with the default variant. */
export default function PrimaryButton(props: ButtonProps<'button'>) {
    return <Button variant="default" {...props} />;
}
