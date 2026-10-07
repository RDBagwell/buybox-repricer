import { OctagonX, Power } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/** The one big button. Always visible in the header; both directions need a confirmation. */
export function KillSwitch({
    on,
    disabled,
    onChange,
}: {
    on: boolean;
    disabled?: boolean;
    onChange: (on: boolean) => Promise<void>;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);

    const confirm = async () => {
        setBusy(true);
        await onChange(!on);
        setBusy(false);
        setConfirming(false);
    };

    return (
        <>
            <Button
                type="button"
                size="lg"
                disabled={disabled}
                onClick={() => setConfirming(true)}
                className={
                    on
                        ? 'bg-emerald-700 text-white hover:bg-emerald-800'
                        : 'bg-red-700 text-white shadow-[0_0_0_3px_rgba(185,28,28,0.18)] hover:bg-red-800'
                }
                aria-label={
                    on
                        ? 'Kill switch is on. Resume repricing.'
                        : 'Kill switch: stop all repricing'
                }
            >
                {on ? (
                    <Power className="size-5" />
                ) : (
                    <OctagonX className="size-5" />
                )}
                <span className="font-semibold">
                    {on ? 'Resume repricing' : 'Kill switch'}
                </span>
            </Button>
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {on
                                ? 'Turn the kill switch off?'
                                : 'Stop all repricing?'}
                        </DialogTitle>
                        <DialogDescription>
                            {on
                                ? 'Decisions will start pricing and pushing again for every product that is not paused.'
                                : 'Every product stops repricing immediately. Queued price pushes are cancelled. Decisions are still recorded (as skipped) so you can see what would have happened.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirm}
                            disabled={busy}
                            className={
                                on
                                    ? 'bg-emerald-700 hover:bg-emerald-800'
                                    : 'bg-red-700 hover:bg-red-800'
                            }
                        >
                            {on ? 'Resume repricing' : 'Stop everything'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
