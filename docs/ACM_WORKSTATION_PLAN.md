# ACM Officer Workstation and Voter Entry

## Summary

Make the voter experience the default entry point and give the election officer a focused, three-pane ACM workstation. Preserve the existing voting, printing, and interim return mechanisms wherever possible.

## Key changes

- Redirect `/` to the existing role-demo voter page. Hide the self-service control-number option, and add a small, unobtrusive link from the voter UI to the officer workstation.
- Organize the officer workstation into three persistent panes: Generate control number, Print ballot, and Print current/interim election return. Keep precinct and printer status visible, with secondary demo controls accessible outside the primary workflow.
- Continue using the same four-digit number for admission and printing. After the officer enters it, check whether the ballot is ready without consuming the number or starting the printer. Show Ballot ready and a separate Print ballot button.
- Revalidate the number and ballot state when printing is requested. Preserve existing printing and ballot-deposit behavior, and show clear feedback for invalid, unavailable, already-used, or failed print requests without exposing selections to the officer.
- Use the existing return-generation and print flows. Clearly identify the return as current/interim and show its precinct and point-in-time context; verify the printed artifact conveys that context as well.
- Update the voter completion instructions to say that the voter should return to the officer with the same number to collect the printed ballot.

## Test plan

- Cover the complete officer-issued number → voter submission → readiness check → explicit print flow.
- Verify readiness checks do not print or consume the number; test invalid, not-ready, already-used, and print-failure cases.
- Verify the home redirect, hidden self-service option, officer navigation, and interim return generation while the precinct remains open.
- Run the affected feature tests and relevant frontend checks. Inspect the workstation and voter flow in the browser, including a tablet-sized viewport.

## Assumptions

- The existing demo hub and secondary tools remain available; the new workstation becomes the officer’s focused entry point.
- The officer link is navigation, not an access-control mechanism.
- The compass emphasizes a focused voter journey, an understandable officer workflow, ballot privacy, and safe reuse of existing behavior, alongside an acceptance checklist.
