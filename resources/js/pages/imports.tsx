import { CandidateInbox } from '@/components/gf/candidate-inbox';
import { Page } from '@/components/gf/page';

export default function Imports() {
    return (
        <Page title="メール">
            <CandidateInbox kind="mail" />
        </Page>
    );
}
