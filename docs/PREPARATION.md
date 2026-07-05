# Alternative Election System
## Preparing the Precinct Election Appliance
### From National Preparation to Friday Certification

> **Working Draft**
>
> This document describes the lifecycle of a precinct election appliance before Election Day.
>
> It explains how a generic device becomes an official precinct appliance, how confidence is established before voting begins, and why no critical election information should exist only inside the device.

---

# 1. Introduction

One of the fundamental design goals of the Alternative Election System is to eliminate the notion of a "special" election machine.

Every Raspberry Pi should be identical.

Every device should contain exactly the same software.

Every device should be capable of serving any precinct in the country.

The identity of a precinct should not be permanently programmed into a machine.

Instead...

a machine becomes a precinct appliance only after it receives its official Election Package.

This dramatically simplifies deployment, replacement, recovery, auditing, and long-term maintenance.

---

# 2. National Preparation

Long before Election Day, the Election Management Body prepares the national election information.

This information already exists as part of the normal election preparation process.

Examples include:

- official list of precincts
- official list of candidates
- electoral districts
- contest definitions
- ballot styles
- election calendar
- cryptographic verification material

These become the National Election Registries.

The registries are signed and frozen.

Once finalized...

they become the reference against which every precinct appliance operates.

---

# 3. Universal Election Image

A single software image is prepared.

Every Raspberry Pi receives the exact same installation.

The image contains:

- Raspberry Pi OS
- Laravel application
- Vue Progressive Web Application
- printer support
- scanner support
- QR generation libraries
- QR decoding libraries
- nationwide precinct registry
- nationwide candidate registry
- public verification keys

Notice what is intentionally absent.

The device does **not** yet know:

- which precinct it belongs to
- which ballot style it will use
- which local candidates will appear
- whether it will ever be deployed

At this stage...

every device is identical.

---

# 4. Preparing the Election Package

The nationwide registries describe the entire country.

Individual precincts require only a small subset.

The Election Compiler processes the national information and produces one Election Package for every precinct.

The Election Package does not duplicate the nationwide registries.

Instead...

it identifies the information required to derive the precinct-specific configuration.

The package includes information such as:

- precinct identifier
- ballot style identifier
- applicable contests
- registry versions
- package hashes
- package signature

This package is the authority that transforms a generic device into a precinct appliance.

---

# 5. Distribution

The Election Package may be distributed in several forms.

Examples include:

- SD card
- printed QR package
- future electronic transport mechanisms

The transport mechanism is not important.

The contents are.

Regardless of how the package arrives...

the information must always be identical.

---

# 6. Activating the Device

When the precinct receives its appliance...

the device is still generic.

An Election Officer performs a simple activation ceremony.

The officer either:

- inserts the official Election Package SD card, or
- scans the printed Election Package.

The application verifies:

- package signature
- registry versions
- package integrity

If verification succeeds...

the appliance derives its precinct configuration.

This includes:

- local ballot definition
- contest ordering
- local candidate ordinal mapping
- ballot hashes
- operational metadata

The derived configuration is persisted.

From this point onward...

the appliance behaves as that specific precinct.

---

# 7. Deterministic Configuration

The derived precinct configuration is not downloaded.

It is computed.

Every certified appliance supplied with:

- the same national registries
- the same Election Package

must derive exactly the same configuration.

This property is essential.

It guarantees that replacing hardware does not alter the election.

Any replacement appliance will derive the identical ballot definition.

---

# 8. Printing the Precinct Package

The Election Package itself should exist outside electronics.

Official printed copies are prepared.

These include:

- precinct identification
- activation QR package
- package hashes
- verification information

The printed package serves multiple purposes.

It may be used to:

- activate replacement hardware
- verify package integrity
- recover from media failure
- support independent auditing

Nothing critical should exist only inside a storage device.

---

# 9. Friday Certification

On the Friday preceding Election Day...

every precinct performs an end-to-end certification.

This is not merely a hardware test.

It is a complete rehearsal of the election lifecycle.

The objective is simple.

Demonstrate that the appliance produces the expected election artifacts before any voter arrives.

---

# 10. Certification Activities

The certification process verifies every critical subsystem.

Examples include:

- package verification
- precinct activation
- ballot derivation
- printer operation
- scanner operation
- QR generation
- QR decoding
- counting workflow
- Election Return generation

Every step is recorded.

Nothing is assumed.

---

# 11. Certification Ballots

A predefined set of Certification Ballots accompanies every precinct.

These ballots contain known selections.

Their expected tally is already known.

Their expected Election Return is already known.

Their expected Election Return hash is already known.

The appliance scans these ballots exactly as it will scan official ballots.

The resulting Election Return must match the expected result exactly.

If it does not...

the appliance is not certified.

---

# 12. Certification Reports

At the conclusion of testing...

the appliance generates a Certification Report.

The report documents:

- software version
- package version
- registry versions
- mapping hash
- printer verification
- scanner verification
- Certification Ballot results
- expected Election Return hash
- generated Election Return hash

The report may later be electronically signed by the Election Officers.

The report itself becomes part of the election record.

---

# 13. Resetting the Appliance

After successful certification...

all Certification Ballots are removed from operational storage.

The counting journal is emptied.

The election data is reset.

Only the certified precinct configuration remains.

The appliance is now ready for Election Day.

---

# 14. Sealing the Appliance

Once certification has completed successfully...

the appliance enters a sealed state.

No precinct configuration changes are permitted.

The ballot definition is frozen.

The mapping hashes are frozen.

Any modification invalidates certification.

The precinct begins Election Day with confidence that the certified appliance is identical to the appliance that completed Friday's verification.

---

# 15. Recovery

Should the appliance fail after certification...

recovery is intentionally simple.

A replacement Raspberry Pi receives:

- the same universal software image
- the same national registries
- the same Election Package

The replacement derives the identical precinct configuration.

The replacement appliance is therefore functionally indistinguishable from the original.

No hidden databases need to be restored.

No proprietary backups need to be recovered.

No technician needs to reconstruct election data.

---

# 16. Design Philosophy

Preparing an election appliance should resemble preparing scientific equipment.

The objective is not merely to install software.

The objective is to demonstrate that:

- the configuration is correct,
- the hardware is functioning,
- the election artifacts are reproducible,
- and the appliance behaves exactly as expected.

Confidence should not come from trusting the machine.

Confidence should come from repeatedly proving that the machine produces the same results under the same conditions.

---

# Epilogue

The preparation of the precinct appliance is intentionally uneventful.

There are no complicated installation procedures.

No hidden configuration screens.

No specialized technical knowledge required at the precinct.

A universal appliance receives its official Election Package.

It derives its configuration.

It proves itself through certification.

It is sealed.

Then it waits.

Not to decide the election...

but to faithfully assist the people who do.
