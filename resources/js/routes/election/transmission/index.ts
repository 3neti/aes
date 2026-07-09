import { queryParams, type RouteQueryOptions, type RouteDefinition, type RouteFormDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Election\TransmissionController::preparePackage
* @see app/Http/Controllers/Election/TransmissionController.php:20
* @route '/election/transmission/package'
*/
export const preparePackage = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preparePackage.url(options),
    method: 'post',
})

preparePackage.definition = {
    methods: ["post"],
    url: '/election/transmission/package',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Election\TransmissionController::preparePackage
* @see app/Http/Controllers/Election/TransmissionController.php:20
* @route '/election/transmission/package'
*/
preparePackage.url = (options?: RouteQueryOptions) => {
    return preparePackage.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::preparePackage
* @see app/Http/Controllers/Election/TransmissionController.php:20
* @route '/election/transmission/package'
*/
preparePackage.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: preparePackage.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::preparePackage
* @see app/Http/Controllers/Election/TransmissionController.php:20
* @route '/election/transmission/package'
*/
const preparePackageForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preparePackage.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::preparePackage
* @see app/Http/Controllers/Election/TransmissionController.php:20
* @route '/election/transmission/package'
*/
preparePackageForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: preparePackage.url(options),
    method: 'post',
})

preparePackage.form = preparePackageForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:26
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
* @see app/Http/Controllers/Election/TransmissionController.php:26
* @route '/election/transmission/send'
*/
send.url = (options?: RouteQueryOptions) => {
    return send.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:26
* @route '/election/transmission/send'
*/
send.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: send.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:26
* @route '/election/transmission/send'
*/
const sendForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: send.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::send
* @see app/Http/Controllers/Election/TransmissionController.php:26
* @route '/election/transmission/send'
*/
sendForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: send.url(options),
    method: 'post',
})

send.form = sendForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:34
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
* @see app/Http/Controllers/Election/TransmissionController.php:34
* @route '/election/transmission/custody'
*/
custody.url = (options?: RouteQueryOptions) => {
    return custody.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:34
* @route '/election/transmission/custody'
*/
custody.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: custody.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:34
* @route '/election/transmission/custody'
*/
const custodyForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: custody.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::custody
* @see app/Http/Controllers/Election/TransmissionController.php:34
* @route '/election/transmission/custody'
*/
custodyForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: custody.url(options),
    method: 'post',
})

custody.form = custodyForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::recordReceipt
* @see app/Http/Controllers/Election/TransmissionController.php:43
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
* @see \App\Http\Controllers\Election\TransmissionController::recordReceipt
* @see app/Http/Controllers/Election/TransmissionController.php:43
* @route '/election/transmission/receipt'
*/
receipt.url = (options?: RouteQueryOptions) => {
    return receipt.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::recordReceipt
* @see app/Http/Controllers/Election/TransmissionController.php:43
* @route '/election/transmission/receipt'
*/
receipt.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: receipt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::recordReceipt
* @see app/Http/Controllers/Election/TransmissionController.php:43
* @route '/election/transmission/receipt'
*/
const receiptForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: receipt.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::recordReceipt
* @see app/Http/Controllers/Election/TransmissionController.php:43
* @route '/election/transmission/receipt'
*/
receiptForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: receipt.url(options),
    method: 'post',
})

receipt.form = receiptForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyOfficer
* @see app/Http/Controllers/Election/TransmissionController.php:36
* @route '/election/transmission/officer-verification'
*/
export const officerVerification = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: officerVerification.url(options),
    method: 'post',
})

officerVerification.definition = {
    methods: ["post"],
    url: '/election/transmission/officer-verification',
} satisfies RouteDefinition<['post']>

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyOfficer
* @see app/Http/Controllers/Election/TransmissionController.php:36
* @route '/election/transmission/officer-verification'
*/
officerVerification.url = (options?: RouteQueryOptions) => {
    return officerVerification.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyOfficer
* @see app/Http/Controllers/Election/TransmissionController.php:36
* @route '/election/transmission/officer-verification'
*/
officerVerification.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: officerVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyOfficer
* @see app/Http/Controllers/Election/TransmissionController.php:36
* @route '/election/transmission/officer-verification'
*/
const officerVerificationForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: officerVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyOfficer
* @see app/Http/Controllers/Election/TransmissionController.php:36
* @route '/election/transmission/officer-verification'
*/
officerVerificationForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: officerVerification.url(options),
    method: 'post',
})

officerVerification.form = officerVerificationForm

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyRecipient
* @see app/Http/Controllers/Election/TransmissionController.php:39
* @route '/election/transmission/recipient-verification'
*/
export const recipientVerification = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: recipientVerification.url(options),
    method: 'post',
})

recipientVerification.definition = {
    methods: ["post"],
    url: '/election/transmission/recipient-verification',
} satisfies RouteDefinition<['post']>

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyRecipient
* @see app/Http/Controllers/Election/TransmissionController.php:39
* @route '/election/transmission/recipient-verification'
*/
recipientVerification.url = (options?: RouteQueryOptions) => {
    return recipientVerification.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyRecipient
* @see app/Http/Controllers/Election/TransmissionController.php:39
* @route '/election/transmission/recipient-verification'
*/
recipientVerification.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: recipientVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyRecipient
* @see app/Http/Controllers/Election/TransmissionController.php:39
* @route '/election/transmission/recipient-verification'
*/
const recipientVerificationForm = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: recipientVerification.url(options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Election\TransmissionController::verifyRecipient
* @see app/Http/Controllers/Election/TransmissionController.php:39
* @route '/election/transmission/recipient-verification'
*/
recipientVerificationForm.post = (options?: RouteQueryOptions): RouteFormDefinition<'post'> => ({
    action: recipientVerification.url(options),
    method: 'post',
})

recipientVerification.form = recipientVerificationForm

const transmission = {
    preparePackage: Object.assign(preparePackage, preparePackage),
    receipt: Object.assign(receipt, receipt),
    officerVerification: Object.assign(officerVerification, officerVerification),
    recipientVerification: Object.assign(recipientVerification, recipientVerification),
    send: Object.assign(send, send),
    custody: Object.assign(custody, custody),
}

export default transmission
