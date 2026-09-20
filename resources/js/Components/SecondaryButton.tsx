import Button, { type ButtonProps } from './Button';

/** @deprecated Use Button variant="outline". */
export default function SecondaryButton(props: ButtonProps<'button'>) {
    return <Button variant="outline" {...props} />;
}
