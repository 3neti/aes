import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Election\TransmissionController::prepare
* @see app/Http/Controllers/Election/TransmissionController.php:47
* @route '/election/transmission/package'
*/
export const prepare = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: prepare.url(options),
    method: 'post',
})

prepare.definition = {
    methods: ["post"],
    url: '/election/transmission/package',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::prepare
* @see app/Http/Controllers/Election/TransmissionController.php:47
* @route '/election/transmission/package'
*/
prepare.url = (options?: RouteQueryOptions) => {
    return prepare.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::prepare
* @see app/Http/Controllers/Election/TransmissionController.php:47
* @route '/election/transmission/package'
*/
prepare.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: prepare.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::prepare
* @see app/Http/Controllers/Election/TransmissionController.php:47
* @route '/election/transmission/package'
*/
const prepareForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prepare.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::prepare
* @see app/Http/Controllers/Election/TransmissionController.php:47
* @route '/election/transmission/package'
*/
prepareForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: prepare.url(options),
    method: 'post',
})

prepare.form = prepareForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::officerVerification
* @see app/Http/Controllers/Election/TransmissionController.php:54
* @route '/election/transmission/officer-verification'
*/
export const officerVerification = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: officerVerification.url(options),
    method: 'post',
})

officerVerification.definition = {
    methods: ["post"],
    url: '/election/transmission/officer-verification',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::officerVerification
* @see app/Http/Controllers/Election/TransmissionController.php:54
* @route '/election/transmission/officer-verification'
*/
officerVerification.url = (options?: RouteQueryOptions) => {
    return officerVerification.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::officerVerification
* @see app/Http/Controllers/Election/TransmissionController.php:54
* @route '/election/transmission/officer-verification'
*/
officerVerification.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: officerVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::officerVerification
* @see app/Http/Controllers/Election/TransmissionController.php:54
* @route '/election/transmission/officer-verification'
*/
const officerVerificationForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: officerVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::officerVerification
* @see app/Http/Controllers/Election/TransmissionController.php:54
* @route '/election/transmission/officer-verification'
*/
officerVerificationForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: officerVerification.url(options),
    method: 'post',
})

officerVerification.form = officerVerificationForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::recipientVerification
* @see app/Http/Controllers/Election/TransmissionController.php:61
* @route '/election/transmission/recipient-verification'
*/
export const recipientVerification = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: recipientVerification.url(options),
    method: 'post',
})

recipientVerification.definition = {
    methods: ["post"],
    url: '/election/transmission/recipient-verification',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::recipientVerification
* @see app/Http/Controllers/Election/TransmissionController.php:61
* @route '/election/transmission/recipient-verification'
*/
recipientVerification.url = (options?: RouteQueryOptions) => {
    return recipientVerification.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::recipientVerification
* @see app/Http/Controllers/Election/TransmissionController.php:61
* @route '/election/transmission/recipient-verification'
*/
recipientVerification.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: recipientVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::recipientVerification
* @see app/Http/Controllers/Election/TransmissionController.php:61
* @route '/election/transmission/recipient-verification'
*/
const recipientVerificationForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: recipientVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::recipientVerification
* @see app/Http/Controllers/Election/TransmissionController.php:61
* @route '/election/transmission/recipient-verification'
*/
recipientVerificationForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: recipientVerification.url(options),
    method: 'post',
})

recipientVerification.form = recipientVerificationForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::receipt
* @see app/Http/Controllers/Election/TransmissionController.php:75
* @route '/election/transmission/receipt'
*/
export const receipt = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: receipt.url(options),
    method: 'post',
})

receipt.definition = {
    methods: ["post"],
    url: '/election/transmission/receipt',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::receipt
* @see app/Http/Controllers/Election/TransmissionController.php:75
* @route '/election/transmission/receipt'
*/
receipt.url = (options?: RouteQueryOptions) => {
    return receipt.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::receipt
* @see app/Http/Controllers/Election/TransmissionController.php:75
* @route '/election/transmission/receipt'
*/
receipt.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: receipt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::receipt
* @see app/Http/Controllers/Election/TransmissionController.php:75
* @route '/election/transmission/receipt'
*/
const receiptForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: receipt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::receipt
* @see app/Http/Controllers/Election/TransmissionController.php:75
* @route '/election/transmission/receipt'
*/
receiptForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: receipt.url(options),
    method: 'post',
})

receipt.form = receiptForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::finalBackup
* @see app/Http/Controllers/Election/TransmissionController.php:82
* @route '/election/transmission/final-backup'
*/
export const finalBackup = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: finalBackup.url(options),
    method: 'post',
})

finalBackup.definition = {
    methods: ["post"],
    url: '/election/transmission/final-backup',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::finalBackup
* @see app/Http/Controllers/Election/TransmissionController.php:82
* @route '/election/transmission/final-backup'
*/
finalBackup.url = (options?: RouteQueryOptions) => {
    return finalBackup.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::finalBackup
* @see app/Http/Controllers/Election/TransmissionController.php:82
* @route '/election/transmission/final-backup'
*/
finalBackup.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: finalBackup.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::finalBackup
* @see app/Http/Controllers/Election/TransmissionController.php:82
* @route '/election/transmission/final-backup'
*/
const finalBackupForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: finalBackup.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::finalBackup
* @see app/Http/Controllers/Election/TransmissionController.php:82
* @route '/election/transmission/final-backup'
*/
finalBackupForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: finalBackup.url(options),
    method: 'post',
})

finalBackup.form = finalBackupForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:68
* @route '/election/transmission/send'
*/
export const send = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: send.url(options),
    method: 'post',
})

send.definition = {
    methods: ["post"],
    url: '/election/transmission/send',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:68
* @route '/election/transmission/send'
*/
send.url = (options?: RouteQueryOptions) => {
    return send.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:68
* @route '/election/transmission/send'
*/
send.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: send.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:68
* @route '/election/transmission/send'
*/
const sendForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: send.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:68
* @route '/election/transmission/send'
*/
sendForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: send.url(options),
    method: 'post',
})

send.form = sendForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:89
* @route '/election/transmission/custody'
*/
export const custody = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: custody.url(options),
    method: 'post',
})

custody.definition = {
    methods: ["post"],
    url: '/election/transmission/custody',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:89
* @route '/election/transmission/custody'
*/
custody.url = (options?: RouteQueryOptions) => {
    return custody.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:89
* @route '/election/transmission/custody'
*/
custody.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: custody.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:89
* @route '/election/transmission/custody'
*/
const custodyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: custody.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:89
* @route '/election/transmission/custody'
*/
custodyForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: custody.url(options),
    method: 'post',
})

custody.form = custodyForm

const transmission = {
    prepare: Object.assign(prepare, prepare),
    officerVerification: Object.assign(officerVerification, officerVerification),
    recipientVerification: Object.assign(recipientVerification, recipientVerification),
    receipt: Object.assign(receipt, receipt),
    finalBackup: Object.assign(finalBackup, finalBackup),
    send: Object.assign(send, send),
    custody: Object.assign(custody, custody),
}

export default transmission