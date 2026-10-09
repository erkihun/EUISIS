import Button, { type ButtonProps } from './Button';

/** @deprecated Use Button variant="destructive". */
export default function DangerButton(props: ButtonProps<'button'>) {
    return <Button variant="destructive" {...props} />;
}
