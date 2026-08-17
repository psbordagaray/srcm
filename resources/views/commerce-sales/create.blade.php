<x-app-layout>
    @php
        $selectedServiceId = old(
            'service_order_id',
            $selectedServiceOrder?->id
        );
        $selectedCustomerId = old(
            'customer_business_party_id',
            $selectedServiceOrder?->customer_business_party_id
        );
        $productRows = old('product_lines', []);

        if (! is_array($productRows) || $productRows === []) {
            $productRows = [[
                'catalog_product_id' => '',
                'source_location_id' => '',
                'condition' => \App\Enums\InventoryCondition::New->value,
                'quantity' => '1',
            ]];
        }

        $paymentRows = old('payments', []);

        if (! is_array($paymentRows)) {
            $paymentRows = [];
        }

        $paymentValidationFailed = collect($errors->keys())->contains(
            fn (string $key): bool =>
                $key === 'commerce'
                || $key === 'payments'
                || $key === 'receivable_amount'
                || $key === 'receivable_due_on'
                || str_starts_with($key, 'payments.')
        );

        $serviceTotals = [];
        $serviceCurrencies = [];

        foreach ($unsettledOrders as $order) {
            $quote = $order->quotes->sortByDesc('revision')->first();
            $option = $quote?->decision?->selectedOption;

            $serviceTotals[(string) $order->id] =
                (int) ($option?->total_minor ?? 0);
            $serviceCurrencies[(string) $order->id] =
                (string) ($quote?->currency_code ?? '');
        }

        $paymentMethodLabels = collect($paymentMethods)->mapWithKeys(
            fn ($method): array => [$method->value => $method->label()]
        )->all();
    @endphp

    <div class="mx-auto max-w-6xl space-y-6">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.2em] text-amber-300">Comercio · Confirmación atómica</p>
            <h1 class="mt-2 text-2xl font-bold text-white">Nueva venta y cobro</h1>
            <p class="mt-2 text-sm text-slate-400">La reparación se deriva del presupuesto aprobado. Los productos generan su salida de inventario y la venta se liquida con pagos recibidos más, si un Administrador lo autoriza, saldo pendiente en cuenta corriente.</p>
            <div class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl border border-slate-700/80 bg-slate-900/70 px-3 py-2 text-xs font-semibold text-slate-300" data-sale-shortcut-help>
                <span class="rounded-md bg-slate-800 px-2 py-1 font-mono text-amber-200">F1</span><span>Nueva venta</span>
                <span class="text-slate-600">·</span>
                <span class="rounded-md bg-slate-800 px-2 py-1 font-mono text-cyan-200">F3</span><span>Artículos</span>
                <span class="text-slate-600">·</span>
                <span class="rounded-md bg-slate-800 px-2 py-1 font-mono text-emerald-200">F7</span><span>Cobro</span>
                <span class="sr-only">F1 Nueva venta · F3 Artículos · F7 Cobro</span>
            </div>
        </div>

        @if($errors->any())
            <div role="alert" class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-100">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('commerce-sales.store') }}"
            class="space-y-6"
            data-sale-explicit-submit-only
            @keydown.enter="if (! ['TEXTAREA', 'BUTTON', 'SELECT'].includes($event.target.tagName)) { $event.preventDefault() }"
            @keydown.window="handleSaleShortcut($event)"
            @keydown.escape.window="if (paymentOverlayOpen) { $event.preventDefault(); $event.stopPropagation(); backPaymentLevel() }"
            x-effect="document.documentElement.classList.toggle('overflow-hidden', paymentOverlayOpen)"
            x-data="{
                currencyCode: {{ \Illuminate\Support\Js::from(old('currency_code', 'ARS')) }},
                serviceOrderId: {{ \Illuminate\Support\Js::from((string) $selectedServiceId) }},
                customerBusinessPartyId: {{ \Illuminate\Support\Js::from((string) $selectedCustomerId) }},
                customerName: {{ \Illuminate\Support\Js::from(old('customer_name', '')) }},
                customerDocument: {{ \Illuminate\Support\Js::from(old('customer_document', '')) }},
                canCreateCustomerReceivable: {{ \Illuminate\Support\Js::from($canCreateCustomerReceivable) }},
                receivableAmount: {{ \Illuminate\Support\Js::from(old('receivable_amount', '')) }},
                receivableDueOn: {{ \Illuminate\Support\Js::from(old('receivable_due_on', '')) }},
                setupOpen: false,
                customerOpen: false,
                paymentOverlayOpen: {{ \Illuminate\Support\Js::from($paymentValidationFailed) }},
                paymentReviewOpen: false,
                paymentError: '',
                activeCashSession: {{ \Illuminate\Support\Js::from(
                    $activeCashSession
                        ? [
                            'id' => (int) $activeCashSession->id,
                            'register_name' => $activeCashSession->register->name,
                            'financial_account_id' => (int) $activeCashSession->register->financialAccount->id,
                            'financial_account_label' => $activeCashSession->register->financialAccount->name.' · '.$activeCashSession->currency_code.' · Caja de efectivo',
                            'currency_code' => $activeCashSession->currency_code,
                        ]
                        : null
                ) }},
                productLines: {{ \Illuminate\Support\Js::from(array_values($productRows)) }}.filter(
                    line => Boolean(line.catalog_product_id)
                ),
                draftProduct: {
                    catalog_product_id: '',
                    source_location_id: '',
                    condition: '',
                    quantity: ''
                },
                articleQuery: '',
                articleResultsOpen: false,
                articleActiveIndex: -1,
                editingProductIndex: null,
                productDraftError: '',
                cartQuantityError: '',
                payments: {{ \Illuminate\Support\Js::from(array_values($paymentRows)) }}.map(payment => ({
                    financial_account_id: '',
                    tendered_amount: '',
                    card_brand: '',
                    card_network: '',
                    card_last4: '',
                    installments: '',
                    processor: '',
                    external_operation_id: '',
                    authorization_code: '',
                    provider_status: '',
                    ...payment,
                    _manual: true,
                    _tenderManual: String(
                        payment.tendered_amount ?? ''
                    ).trim() !== ''
                })),
                serviceTotals: {{ \Illuminate\Support\Js::from($serviceTotals) }},
                serviceCurrencies: {{ \Illuminate\Support\Js::from($serviceCurrencies) }},
                paymentMethodLabels: {{ \Illuminate\Support\Js::from($paymentMethodLabels) }},
                customerCreditBalances: {{ \Illuminate\Support\Js::from($customerCreditBalances) }},
                financialAccounts: {{ \Illuminate\Support\Js::from(
                    $financialAccounts->map(
                        fn ($account) => [
                            'id' => (int) $account->id,
                            'name' => $account->name,
                            'type' => $account->type->label(),
                            'provider' => $account->provider,
                            'currency_code' => $account->currency_code,
                            'label' => $account->name.' · '.
                                $account->currency_code.' · '.
                                $account->type->label(),
                        ]
                    )->values()->all()
                ) }},
                productPrices: {{ \Illuminate\Support\Js::from($productPrices) }},
                availability: {{ \Illuminate\Support\Js::from($availabilityMatrix) }},
                productSearchIndex: {{ \Illuminate\Support\Js::from($productSearchIndex) }},
                productCatalog: {{ \Illuminate\Support\Js::from(
                    $products->mapWithKeys(
                        fn ($product) => [
                            (string) $product->id => [
                                'sku' => $product->sku,
                                'name' => $product->name,
                                'unit' => $product->base_unit_code,
                                'scale' => (int) $product->quantity_scale,
                            ],
                        ]
                    )->all()
                ) }},
                locationCatalog: {{ \Illuminate\Support\Js::from(
                    $locations->mapWithKeys(
                        fn ($location) => [
                            (string) $location->id => $location->name,
                        ]
                    )->all()
                ) }},
                conditionLabels: {{ \Illuminate\Support\Js::from(
                    collect($conditions)->mapWithKeys(
                        fn ($condition) => [
                            $condition->value => $condition->label(),
                        ]
                    )->all()
                ) }},
                serviceLabels: {{ \Illuminate\Support\Js::from(
                    $unsettledOrders->mapWithKeys(
                        fn ($order) => [
                            (string) $order->id =>
                                '#'.$order->order_number.' · '.
                                ($order->customer?->name
                                    ?? $order->delivery?->recipient_name
                                    ?? 'Cliente'),
                        ]
                    )->all()
                ) }},
                customerLabels: {{ \Illuminate\Support\Js::from(
                    $customers->mapWithKeys(
                        fn ($customer) => [
                            (string) $customer->id =>
                                $customer->name.
                                ($customer->tax_id
                                    ? ' · '.$customer->tax_id
                                    : ''),
                        ]
                    )->all()
                ) }},
                init() {
                    this.payments.forEach(payment => {
                        this.syncOperationalCashAccount(payment);
                        this.syncCashTenderToApplied(payment);
                    });
                    this.$watch(
                        'productLines',
                        () => this.syncAutomaticPayment()
                    );
                    this.$watch(
                        'serviceOrderId',
                        () => this.syncAutomaticPayment()
                    );
                    this.$watch(
                        'receivableAmount',
                        () => this.syncAutomaticPayment()
                    );
                    this.$watch(
                        'currencyCode',
                        () => {
                            this.payments.forEach(payment => {
                                this.ensurePaymentAccountCurrency(payment);
                                this.syncOperationalCashAccount(payment);
                            });
                            this.syncAutomaticPayment();
                        }
                    );
                },
                blankPayment(method = '') {
                    return {
                        method,
                        amount: '',
                        financial_account_id: '',
                        tendered_amount: '',
                        reference: '',
                        card_brand: '',
                        card_network: '',
                        card_last4: '',
                        installments: '',
                        processor: '',
                        external_operation_id: '',
                        authorization_code: '',
                        provider_status: '',
                        notes: '',
                        _manual: true,
                        _tenderManual: false
                    };
                },
                addPayment(method = '') {
                    if (
                        method === 'cash'
                        && ! this.cashSessionAvailable()
                    ) {
                        this.paymentError =
                            'Efectivo requiere un turno de caja abierto para la moneda de la venta.';
                        this.paymentReviewOpen = false;
                        return;
                    }

                    if (
                        method === 'account_credit'
                        && ! this.customerBusinessPartyId
                    ) {
                        this.paymentError =
                            'Vinculá un cliente antes de utilizar Crédito en cuenta.';
                        this.paymentReviewOpen = false;
                        return;
                    }

                    if (
                        method === 'account_credit'
                        && this.customerCreditMinor() <= 0
                    ) {
                        this.paymentError =
                            'El cliente no posee saldo a favor disponible en esta moneda.';
                        this.paymentReviewOpen = false;
                        return;
                    }

                    const payment = this.blankPayment(method);
                    this.syncOperationalCashAccount(payment);

                    if (
                        method === 'account_credit'
                        && this.saleTotalMinor() > 0
                        && this.serviceCurrencyMatches()
                    ) {
                        const remaining = Math.max(
                            0,
                            this.saleTotalMinor()
                                - this.paymentTotalMinor()
                                - this.receivableAmountMinor()
                        );
                        payment.amount = this.moneyInput(
                            Math.min(
                                remaining,
                                Math.max(
                                    0,
                                    this.customerCreditMinor()
                                        - this.accountCreditPaymentTotalMinor()
                                )
                            )
                        );
                        payment._manual = false;
                    } else if (
                        this.payments.length === 0
                        && method
                        && this.saleTotalMinor() > 0
                        && this.serviceCurrencyMatches()
                    ) {
                        payment.amount = this.moneyInput(
                            Math.max(
                                0,
                                this.saleTotalMinor()
                                    - this.receivableAmountMinor()
                            )
                        );
                        payment._manual = false;
                        this.syncCashTenderToApplied(payment);
                    }

                    this.payments.push(payment);
                    this.paymentReviewOpen = false;
                    this.paymentError = '';

                    this.$nextTick(() => {
                        const inputs = this.$root.querySelectorAll(
                            '[data-sale-payment-amount]'
                        );
                        inputs[inputs.length - 1]?.focus();
                    });
                },
                removePayment(index) {
                    this.payments.splice(index, 1);
                    this.paymentReviewOpen = false;
                    this.paymentError = '';
                },
                paymentAmountMinor(value) {
                    const normalized = String(value ?? '')
                        .trim()
                        .replace(',', '.');

                    if (! /^\d+(?:\.\d{1,2})?$/.test(normalized)) {
                        return 0;
                    }

                    const amount = Number(normalized);

                    if (! Number.isFinite(amount) || amount <= 0) {
                        return 0;
                    }

                    return Math.round(amount * 100);
                },
                cashTenderedMinor(payment) {
                    return this.paymentAmountMinor(
                        payment.tendered_amount
                    );
                },
                paymentChangeMinor(payment) {
                    if (payment.method !== 'cash') {
                        return 0;
                    }

                    const applied = this.paymentAmountMinor(
                        payment.amount
                    );
                    const tendered = this.cashTenderedMinor(payment);

                    return tendered >= applied
                        ? tendered - applied
                        : 0;
                },
                cashTenderValid(payment) {
                    if (payment.method !== 'cash') {
                        return true;
                    }

                    const applied = this.paymentAmountMinor(
                        payment.amount
                    );
                    const tendered = this.cashTenderedMinor(payment);

                    return applied > 0 && tendered >= applied;
                },
                syncCashTenderToApplied(payment) {
                    if (
                        payment.method !== 'cash'
                        || payment._tenderManual === true
                    ) {
                        return;
                    }

                    payment.tendered_amount =
                        this.paymentAmountMinor(payment.amount) > 0
                            ? payment.amount
                            : '';
                },
                markPaymentTenderManual(payment) {
                    payment._tenderManual = true;
                    this.paymentReviewOpen = false;
                    this.paymentError = '';
                },
                moneyInput(minor) {
                    return (Number(minor || 0) / 100)
                        .toFixed(2)
                        .replace('.', ',');
                },
                paymentTotalMinor() {
                    return this.payments.reduce(
                        (total, payment) =>
                            total + this.paymentAmountMinor(
                                payment.amount
                            ),
                        0
                    );
                },
                serviceCurrencyMatches() {
                    if (! this.serviceOrderId) {
                        return true;
                    }

                    return String(
                        this.serviceCurrencies[
                            Number(this.serviceOrderId)
                        ] ?? ''
                    ) === String(this.currencyCode);
                },
                serviceSubtotalMinor() {
                    if (! this.serviceOrderId) {
                        return 0;
                    }

                    return Number(
                        this.serviceTotals[
                            Number(this.serviceOrderId)
                        ] ?? 0
                    );
                },
                saleTotalMinor() {
                    if (! this.serviceCurrencyMatches()) {
                        return 0;
                    }

                    return this.serviceSubtotalMinor()
                        + this.productsSubtotalMinor();
                },
                receivableAmountMinor() {
                    return this.paymentAmountMinor(
                        this.receivableAmount
                    );
                },
                receivableComplete() {
                    const amount = this.receivableAmountMinor();
                    const dueOn = String(
                        this.receivableDueOn ?? ''
                    ).trim();

                    if (amount <= 0) {
                        return dueOn === '';
                    }

                    return this.canCreateCustomerReceivable
                        && Boolean(
                            this.customerBusinessPartyId
                        );
                },
                settlementTotalMinor() {
                    return this.paymentTotalMinor()
                        + this.receivableAmountMinor();
                },
                paymentDifferenceMinor() {
                    return this.settlementTotalMinor()
                        - this.saleTotalMinor();
                },
                customerCreditMinor() {
                    if (! this.customerBusinessPartyId) {
                        return 0;
                    }

                    return Number(
                        this.customerCreditBalances[
                            Number(
                                this.customerBusinessPartyId
                            )
                        ]?.[this.currencyCode]
                        ?? 0
                    );
                },
                accountCreditPaymentTotalMinor() {
                    return this.payments
                        .filter(
                            payment =>
                                payment.method
                                    === 'account_credit'
                        )
                        .reduce(
                            (total, payment) =>
                                total
                                + this.paymentAmountMinor(
                                    payment.amount
                                ),
                            0
                        );
                },
                paymentMethodLabel(method) {
                    return this.paymentMethodLabels[method]
                        ?? 'Medio sin seleccionar';
                },
                financialAccountOptions() {
                    return this.financialAccounts.filter(
                        account => String(account.currency_code)
                            === String(this.currencyCode)
                    );
                },
                financialAccountFor(id) {
                    return this.financialAccountOptions().find(
                        account => String(account.id) === String(id)
                    ) ?? null;
                },
                financialAccountLabel(id) {
                    return this.financialAccountFor(id)?.label
                        ?? 'Cuenta sin seleccionar';
                },
                cashSessionAvailable() {
                    return Boolean(
                        this.activeCashSession
                        && String(
                            this.activeCashSession.currency_code
                        ) === String(this.currencyCode)
                        && this.financialAccountFor(
                            this.activeCashSession.financial_account_id
                        )
                    );
                },
                syncOperationalCashAccount(payment) {
                    if (payment.method !== 'cash') {
                        return;
                    }

                    payment.financial_account_id =
                        this.cashSessionAvailable()
                            ? String(
                                this.activeCashSession
                                    .financial_account_id
                            )
                            : '';
                },
                onPaymentMethodChanged(payment) {
                    if (
                        payment.method
                            === 'account_credit'
                    ) {
                        payment.financial_account_id = '';
                        payment.tendered_amount = '';
                        payment.reference = '';
                        payment.card_brand = '';
                        payment.card_network = '';
                        payment.card_last4 = '';
                        payment.installments = '';
                        payment.processor = '';
                        payment.external_operation_id = '';
                        payment.authorization_code = '';
                        payment.provider_status = '';
                        payment._tenderManual = false;
                    }

                    if (payment.method === 'cash') {
                        this.syncOperationalCashAccount(payment);
                        this.syncCashTenderToApplied(payment);

                        if (! this.cashSessionAvailable()) {
                            this.paymentError =
                                'Efectivo requiere un turno de caja abierto para la moneda de la venta.';
                        }
                    } else if (
                        this.activeCashSession
                        && String(
                            payment.financial_account_id
                        ) === String(
                            this.activeCashSession
                                .financial_account_id
                        )
                    ) {
                        payment.financial_account_id = '';
                    }

                    if (payment.method !== 'cash') {
                        payment.tendered_amount = '';
                        payment._tenderManual = false;
                    }

                    this.paymentReviewOpen = false;

                    if (
                        payment.method !== 'cash'
                        || this.cashSessionAvailable()
                    ) {
                        this.paymentError = '';
                    }
                },
                ensurePaymentAccountCurrency(payment) {
                    if (
                        payment.financial_account_id
                        && ! this.financialAccountFor(
                            payment.financial_account_id
                        )
                    ) {
                        payment.financial_account_id = '';
                        this.paymentReviewOpen = false;
                        this.paymentError = '';
                    }
                },
                paymentRequiresReference(method) {
                    return Boolean(method)
                        && method !== 'cash'
                        && method !== 'account_credit';
                },
                paymentIsCard(method) {
                    return method === 'debit_card'
                        || method === 'credit_card';
                },
                paymentHasStructuredEvidence(payment) {
                    return [
                        payment.card_brand,
                        payment.card_network,
                        payment.card_last4,
                        payment.installments,
                        payment.processor,
                        payment.external_operation_id,
                        payment.authorization_code,
                        payment.provider_status
                    ].some(value => String(value ?? '').trim() !== '');
                },
                paymentEvidenceSummary(payment) {
                    const parts = [];

                    if (payment.card_brand) {
                        parts.push(`Marca ${payment.card_brand}`);
                    }

                    if (payment.card_network) {
                        parts.push(`Red ${payment.card_network}`);
                    }

                    if (payment.card_last4) {
                        parts.push(`•••• ${payment.card_last4}`);
                    }

                    if (payment.installments) {
                        parts.push(`${payment.installments} cuota${Number(payment.installments) === 1 ? '' : 's'}`);
                    }

                    if (payment.processor) {
                        parts.push(`Procesador ${payment.processor}`);
                    }

                    if (payment.external_operation_id) {
                        parts.push(`Operación ${payment.external_operation_id}`);
                    }

                    if (payment.authorization_code) {
                        parts.push(`Aut. ${payment.authorization_code}`);
                    }

                    if (payment.provider_status) {
                        parts.push(`Estado ${payment.provider_status}`);
                    }

                    return parts.join(' · ');
                },
                paymentComplete(payment) {
                    if (
                        ! payment.method
                        || this.paymentAmountMinor(payment.amount) <= 0
                    ) {
                        return false;
                    }

                    if (
                        payment.method
                            === 'account_credit'
                    ) {
                        return Boolean(
                            this.customerBusinessPartyId
                        )
                            && ! payment.financial_account_id
                            && ! String(
                                payment.reference ?? ''
                            ).trim()
                            && ! String(
                                payment.tendered_amount
                                    ?? ''
                            ).trim()
                            && ! this.paymentHasStructuredEvidence(
                                payment
                            )
                            && this.accountCreditPaymentTotalMinor()
                                <= this.customerCreditMinor();
                    }

                    if (
                        ! this.financialAccountFor(
                            payment.financial_account_id
                        )
                    ) {
                        return false;
                    }

                    if (
                        payment.method === 'cash'
                        && ! this.cashTenderValid(payment)
                    ) {
                        return false;
                    }

                    if (
                        payment.method !== 'cash'
                        && String(
                            payment.tendered_amount ?? ''
                        ).trim() !== ''
                    ) {
                        return false;
                    }

                    if (
                        this.paymentRequiresReference(payment.method)
                        && ! String(payment.reference ?? '').trim()
                    ) {
                        return false;
                    }

                    return true;
                },
                paymentExact() {
                    return this.serviceCurrencyMatches()
                        && this.saleTotalMinor() > 0
                        && (
                            this.payments.length > 0
                            || this.receivableAmountMinor() > 0
                        )
                        && this.payments.every(
                            payment => this.paymentComplete(payment)
                        )
                        && this.receivableComplete()
                        && this.settlementTotalMinor()
                            === this.saleTotalMinor();
                },
                paymentStatus() {
                    if (! this.serviceCurrencyMatches()) {
                        return 'currency';
                    }

                    const total = this.saleTotalMinor();
                    const settled = this.settlementTotalMinor();

                    if (total <= 0) {
                        return 'empty';
                    }

                    if (settled === total) {
                        return (
                            this.payments.length > 0
                            || this.receivableAmountMinor() > 0
                        )
                            && this.payments.every(
                                payment => this.paymentComplete(payment)
                            )
                            && this.receivableComplete()
                                ? 'exact'
                                : 'incomplete';
                    }

                    return settled < total ? 'short' : 'excess';
                },
                paymentStatusAmountMinor() {
                    return Math.abs(this.paymentDifferenceMinor());
                },
                markPaymentAmountManual(payment) {
                    payment._manual = true;
                    this.syncCashTenderToApplied(payment);
                    this.paymentReviewOpen = false;
                    this.paymentError = '';
                },
                syncAutomaticPayment() {
                    if (
                        this.payments.length !== 1
                        || this.payments[0]._manual !== false
                        || ! this.payments[0].method
                        || ! this.serviceCurrencyMatches()
                    ) {
                        return;
                    }

                    this.payments[0].amount =
                        this.saleTotalMinor() > 0
                            ? this.moneyInput(
                                Math.max(
                                    0,
                                    this.saleTotalMinor()
                                        - this.receivableAmountMinor()
                                )
                            )
                            : '';
                    this.syncCashTenderToApplied(this.payments[0]);
                },
                adjustLastPaymentToBalance() {
                    this.paymentError = '';

                    if (this.payments.length === 0) {
                        this.paymentError =
                            'Elegí primero un medio de pago.';
                        return;
                    }

                    const index = this.payments.length - 1;
                    const others = this.payments.reduce(
                        (total, payment, paymentIndex) =>
                            paymentIndex === index
                                ? total
                                : total
                                    + this.paymentAmountMinor(
                                        payment.amount
                                    ),
                        0
                    );
                    const balance = this.saleTotalMinor()
                        - this.receivableAmountMinor()
                        - others;

                    if (balance <= 0) {
                        this.paymentError =
                            'El resto de los pagos ya cubre o supera el total.';
                        return;
                    }

                    this.payments[index].amount =
                        this.moneyInput(balance);
                    this.payments[index]._manual = true;
                    this.syncCashTenderToApplied(
                        this.payments[index]
                    );
                    this.paymentReviewOpen = false;
                },
                openPaymentOverlay() {
                    this.paymentReviewOpen = false;
                    this.paymentError = '';
                    this.paymentOverlayOpen = true;
                },
                closePaymentOverlay() {
                    this.paymentReviewOpen = false;
                    this.paymentError = '';
                    this.paymentOverlayOpen = false;
                },
                backPaymentLevel() {
                    if (this.paymentReviewOpen) {
                        this.paymentReviewOpen = false;
                        this.paymentError = '';
                        return;
                    }

                    this.closePaymentOverlay();
                },
                openPaymentReview() {
                    this.paymentError = '';

                    if (! this.serviceCurrencyMatches()) {
                        this.paymentError =
                            'La moneda de la venta no coincide con el presupuesto aprobado.';
                        return;
                    }

                    if (this.saleTotalMinor() <= 0) {
                        this.paymentError =
                            'La venta todavía no posee un total cobrable.';
                        return;
                    }

                    if (
                        this.payments.length === 0
                        && this.receivableAmountMinor() <= 0
                    ) {
                        this.paymentError =
                            'Registrá al menos un pago o un saldo pendiente autorizado.';
                        return;
                    }

                    if (! this.receivableComplete()) {
                        this.paymentError =
                            this.receivableAmountMinor() > 0
                                ? 'El saldo pendiente requiere autorización de Administrador y cliente vinculado.'
                                : 'No puede informarse vencimiento sin saldo pendiente.';
                        return;
                    }

                    const incomplete = this.payments.findIndex(
                        payment => ! this.paymentComplete(payment)
                    );

                    if (incomplete >= 0) {
                        this.paymentError =
                            `Revisá los datos del pago ${incomplete + 1}.`;
                        return;
                    }

                    if (
                        this.settlementTotalMinor()
                            !== this.saleTotalMinor()
                    ) {
                        this.paymentError =
                            this.settlementTotalMinor()
                                < this.saleTotalMinor()
                                ? `Falta ${
                                    this.currencyCode
                                } ${
                                    this.money(
                                        this.saleTotalMinor()
                                            - this.settlementTotalMinor()
                                    )
                                }.`
                                : `La liquidación excede el total en ${
                                    this.currencyCode
                                } ${
                                    this.money(
                                        this.settlementTotalMinor()
                                            - this.saleTotalMinor()
                                    )
                                }.`;
                        return;
                    }

                    this.paymentReviewOpen = true;
                },
                handleSaleShortcut(event) {
                    if (
                        event.defaultPrevented
                        || event.altKey
                        || event.ctrlKey
                        || event.metaKey
                    ) {
                        return;
                    }

                    if (event.key === 'F3') {
                        event.preventDefault();
                        this.paymentOverlayOpen = false;
                        this.paymentReviewOpen = false;

                        this.$nextTick(() => {
                            this.$refs.productComposer
                                ?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            this.$refs.articleLookup?.focus();
                        });
                        return;
                    }

                    if (event.key === 'F7') {
                        event.preventDefault();
                        this.openPaymentOverlay();
                    }
                },
                blankProduct() {
                    return {
                        catalog_product_id: '',
                        source_location_id: '',
                        condition: '',
                        quantity: ''
                    };
                },
                resetProductComposer() {
                    this.draftProduct = this.blankProduct();
                    this.articleQuery = '';
                    this.articleResultsOpen = false;
                    this.articleActiveIndex = -1;
                    this.editingProductIndex = null;
                    this.productDraftError = '';

                    this.$nextTick(() => {
                        this.$refs.articleLookup?.focus();
                    });
                },
                quantityNumber(value) {
                    return Number(
                        String(value ?? '0').trim().replace(',', '.')
                    );
                },
                productScale(productId) {
                    return Math.max(
                        0,
                        Math.min(
                            6,
                            Number(
                                this.productCatalog[
                                    Number(productId)
                                ]?.scale ?? 0
                            )
                        )
                    );
                },
                normalizedQuantity(value, productId) {
                    const quantity = this.quantityNumber(value);
                    const scale = this.productScale(productId);

                    if (! Number.isFinite(quantity) || quantity <= 0) {
                        return null;
                    }

                    if (scale === 0 && ! Number.isInteger(quantity)) {
                        return null;
                    }

                    return scale === 0
                        ? String(Math.trunc(quantity))
                        : quantity.toFixed(scale);
                },
                priceMinor(productId) {
                    return Number(
                        this.productPrices[this.currencyCode]?.[
                            Number(productId)
                        ] ?? 0
                    );
                },
                money(minor) {
                    return new Intl.NumberFormat('es-AR', {
                        minimumFractionDigits: 2,
                        maximumFractionDigits: 2
                    }).format(Number(minor || 0) / 100);
                },
                lineSubtotalMinor(line) {
                    const quantity = this.quantityNumber(line.quantity);

                    if (! Number.isFinite(quantity) || quantity <= 0) {
                        return 0;
                    }

                    return Math.round(
                        this.priceMinor(line.catalog_product_id) * quantity
                    );
                },
                productsSubtotalMinor() {
                    return this.productLines.reduce(
                        (total, line) =>
                            total + this.lineSubtotalMinor(line),
                        0
                    );
                },
                availabilityKey(line, locationId) {
                    return [
                        line.catalog_product_id,
                        locationId,
                        line.condition || ''
                    ].join(':');
                },
                rawAvailableAt(productId, condition, locationId) {
                    if (! productId || ! condition || ! locationId) {
                        return 0;
                    }

                    return Number(
                        this.availability[
                            [
                                productId,
                                locationId,
                                condition
                            ].join(':')
                        ]?.quantity ?? 0
                    );
                },
                cartCommittedAt(
                    productId,
                    condition,
                    locationId
                ) {
                    return this.productLines.reduce(
                        (total, line, index) => {
                            if (
                                index === this.editingProductIndex
                                || Number(line.catalog_product_id)
                                    !== Number(productId)
                                || String(line.condition)
                                    !== String(condition)
                                || Number(line.source_location_id)
                                    !== Number(locationId)
                            ) {
                                return total;
                            }

                            return total + Math.max(
                                0,
                                this.quantityNumber(line.quantity)
                            );
                        },
                        0
                    );
                },
                availableAtNet(productId, condition, locationId) {
                    return Math.max(
                        0,
                        this.rawAvailableAt(
                            productId,
                            condition,
                            locationId
                        ) - this.cartCommittedAt(
                            productId,
                            condition,
                            locationId
                        )
                    );
                },
                quantityDisplay(value, productId) {
                    const scale = this.productScale(productId);

                    return new Intl.NumberFormat('es-AR', {
                        minimumFractionDigits: scale,
                        maximumFractionDigits: scale
                    }).format(Number(value || 0));
                },
                companyAvailableAllConditions(productId) {
                    if (! productId) {
                        return 0;
                    }

                    let total = 0;

                    Object.entries(this.availability).forEach(
                        ([key, row]) => {
                            const parts = key.split(':');

                            if (
                                Number(parts[0])
                                    === Number(productId)
                            ) {
                                total += Number(row?.quantity ?? 0);
                            }
                        }
                    );

                    this.productLines.forEach((line, index) => {
                        if (
                            index !== this.editingProductIndex
                            && Number(line.catalog_product_id)
                                === Number(productId)
                        ) {
                            total -= Math.max(
                                0,
                                this.quantityNumber(line.quantity)
                            );
                        }
                    });

                    return Math.max(0, total);
                },
                conditionAvailable(productId, condition) {
                    if (! productId || ! condition) {
                        return 0;
                    }

                    let total = 0;

                    Object.entries(this.locationCatalog).forEach(
                        ([locationId]) => {
                            total += this.availableAtNet(
                                productId,
                                condition,
                                locationId
                            );
                        }
                    );

                    return Math.max(0, total);
                },
                conditionOptions() {
                    const productId =
                        this.draftProduct.catalog_product_id;

                    if (! productId) {
                        return [];
                    }

                    return Object.entries(this.conditionLabels)
                        .map(([value, label]) => ({
                            value,
                            label,
                            available: this.conditionAvailable(
                                productId,
                                value
                            )
                        }))
                        .filter(option => option.available > 0);
                },
                locationOptions() {
                    const productId =
                        this.draftProduct.catalog_product_id;
                    const condition =
                        this.draftProduct.condition;

                    if (! productId || ! condition) {
                        return [];
                    }

                    return Object.entries(this.locationCatalog)
                        .map(([id, name]) => ({
                            id: String(id),
                            name,
                            available: this.availableAtNet(
                                productId,
                                condition,
                                id
                            )
                        }))
                        .filter(option => option.available > 0)
                        .sort((left, right) => {
                            if (
                                left.available !== right.available
                            ) {
                                return right.available
                                    - left.available;
                            }

                            return left.name.localeCompare(
                                right.name,
                                'es'
                            );
                        });
                },
                normalizeLookup(value) {
                    return String(value ?? '')
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .toLowerCase()
                        .trim()
                        .replace(/\s+/g, ' ');
                },
                compactLookup(value) {
                    return this.normalizeLookup(value)
                        .replace(/[^a-z0-9]+/g, '');
                },
                articleScore(product) {
                    const query = this.normalizeLookup(
                        this.articleQuery
                    );
                    const compactQuery = this.compactLookup(
                        this.articleQuery
                    );

                    if (! query || ! compactQuery) {
                        return 999;
                    }

                    let best = 999;

                    product.terms.forEach(term => {
                        const value = this.normalizeLookup(
                            term.value
                        );
                        const compactValue = this.compactLookup(
                            term.value
                        );

                        if (
                            term.exact
                            && compactValue === compactQuery
                        ) {
                            best = Math.min(best, 0);
                            return;
                        }

                        if (compactValue === compactQuery) {
                            best = Math.min(best, 1);
                            return;
                        }

                        if (
                            value.startsWith(query)
                            || compactValue.startsWith(
                                compactQuery
                            )
                        ) {
                            best = Math.min(best, 2);
                            return;
                        }

                        if (
                            value.includes(query)
                            || compactValue.includes(
                                compactQuery
                            )
                        ) {
                            best = Math.min(best, 3);
                        }
                    });

                    const words = query
                        .split(' ')
                        .filter(Boolean);
                    const haystack = product.terms
                        .map(term =>
                            this.normalizeLookup(term.value)
                        )
                        .join(' ');

                    if (
                        words.length > 0
                        && words.every(word =>
                            haystack.includes(word)
                        )
                    ) {
                        best = Math.min(best, 4);
                    }

                    return best;
                },
                articleMatches() {
                    if (! this.normalizeLookup(this.articleQuery)) {
                        return [];
                    }

                    return this.productSearchIndex
                        .map(product => ({
                            ...product,
                            score: this.articleScore(product)
                        }))
                        .filter(product => product.score < 999)
                        .sort((left, right) => {
                            if (left.score !== right.score) {
                                return left.score - right.score;
                            }

                            return left.name.localeCompare(
                                right.name,
                                'es'
                            );
                        })
                        .slice(0, 8);
                },
                bestMatchingTerm(product) {
                    const compactQuery = this.compactLookup(
                        this.articleQuery
                    );
                    const query = this.normalizeLookup(
                        this.articleQuery
                    );

                    return product.terms.find(term =>
                        term.exact
                        && this.compactLookup(term.value)
                            === compactQuery
                    ) ?? product.terms.find(term =>
                        this.normalizeLookup(term.value)
                            .includes(query)
                    ) ?? null;
                },
                articleResultMeta(product) {
                    const term = this.bestMatchingTerm(product);

                    if (! term) {
                        return product.sku;
                    }

                    return `${term.kind}: ${term.value}`;
                },
                articleQueryChanged() {
                    if (this.draftProduct.catalog_product_id) {
                        this.draftProduct =
                            this.blankProduct();
                        this.editingProductIndex = null;
                    }

                    this.articleActiveIndex = -1;
                    this.articleResultsOpen = true;
                    this.productDraftError = '';
                },
                moveArticleResult(delta) {
                    const matches = this.articleMatches();

                    if (matches.length === 0) {
                        this.articleActiveIndex = -1;
                        return;
                    }

                    this.articleResultsOpen = true;

                    if (this.articleActiveIndex < 0) {
                        this.articleActiveIndex =
                            delta > 0
                                ? 0
                                : matches.length - 1;
                        return;
                    }

                    this.articleActiveIndex =
                        (
                            this.articleActiveIndex
                            + delta
                            + matches.length
                        ) % matches.length;
                },
                commitArticleSearch() {
                    const matches = this.articleMatches();

                    if (matches.length === 0) {
                        this.productDraftError =
                            'No encontramos un artículo con ese dato.';
                        return;
                    }

                    if (
                        this.articleActiveIndex >= 0
                        && matches[this.articleActiveIndex]
                    ) {
                        this.selectArticle(
                            matches[this.articleActiveIndex]
                        );
                        return;
                    }

                    const exactMatches = matches.filter(
                        product => product.score === 0
                    );

                    if (exactMatches.length === 1) {
                        this.selectArticle(exactMatches[0]);
                        return;
                    }

                    if (
                        exactMatches.length === 0
                        && matches.length === 1
                    ) {
                        this.selectArticle(matches[0]);
                        return;
                    }

                    this.articleResultsOpen = true;
                    this.productDraftError =
                        'Hay más de una coincidencia. Elegí el artículo correcto.';
                },
                selectArticle(product) {
                    this.draftProduct = {
                        catalog_product_id: String(product.id),
                        source_location_id: '',
                        condition: '',
                        quantity: ''
                    };
                    this.articleQuery =
                        `${product.sku} · ${product.name}`;
                    this.articleResultsOpen = false;
                    this.articleActiveIndex = -1;
                    this.productDraftError = '';

                    this.$nextTick(() => {
                        this.resolveConditionSelection();
                    });
                },
                resolveConditionSelection() {
                    const options = this.conditionOptions();

                    if (options.length === 1) {
                        this.draftProduct.condition =
                            options[0].value;

                        this.$nextTick(() => {
                            this.resolveLocationSelection();
                        });
                        return;
                    }

                    if (options.length > 1) {
                        this.$nextTick(() => {
                            this.$refs.productCondition?.focus();
                        });
                    }
                },
                conditionChanged() {
                    this.draftProduct.source_location_id = '';
                    this.draftProduct.quantity = '';
                    this.productDraftError = '';

                    this.$nextTick(() => {
                        this.resolveLocationSelection();
                    });
                },
                resolveLocationSelection() {
                    const options = this.locationOptions();

                    if (options.length === 1) {
                        this.draftProduct.source_location_id =
                            options[0].id;

                        this.$nextTick(() => {
                            this.$refs.productQuantity?.focus();
                        });
                        return;
                    }

                    if (options.length > 1) {
                        this.$nextTick(() => {
                            this.$refs.productLocation?.focus();
                        });
                    }
                },
                locationChanged() {
                    this.draftProduct.quantity = '';
                    this.productDraftError = '';

                    this.$nextTick(() => {
                        this.$refs.productQuantity?.focus();
                    });
                },
                productKey(line) {
                    return [
                        Number(line.catalog_product_id),
                        Number(line.source_location_id),
                        String(line.condition || '')
                    ].join(':');
                },
                addOrUpdateProduct() {
                    this.productDraftError = '';

                    const productId = Number(
                        this.draftProduct.catalog_product_id
                    );
                    const locationId = Number(
                        this.draftProduct.source_location_id
                    );
                    const condition =
                        this.draftProduct.condition;
                    const normalizedQuantity =
                        this.normalizedQuantity(
                            this.draftProduct.quantity,
                            productId
                        );

                    if (! productId) {
                        this.productDraftError =
                            'Buscá y seleccioná un artículo.';
                        return;
                    }

                    if (! condition) {
                        this.productDraftError =
                            'Seleccioná una condición disponible.';
                        return;
                    }

                    if (! locationId) {
                        this.productDraftError =
                            'Seleccioná una ubicación con disponibilidad.';
                        return;
                    }

                    if (normalizedQuantity === null) {
                        this.productDraftError =
                            this.productScale(productId) === 0
                                ? 'Ingresá una cantidad entera mayor que cero.'
                                : 'Ingresá una cantidad válida mayor que cero.';
                        return;
                    }

                    if (this.priceMinor(productId) <= 0) {
                        this.productDraftError =
                            'El artículo no tiene precio vigente en '+
                            this.currencyCode+'.';
                        return;
                    }

                    const requested = this.quantityNumber(
                        normalizedQuantity
                    );
                    const available = this.availableAtNet(
                        productId,
                        condition,
                        locationId
                    );

                    if (requested > available) {
                        this.productDraftError =
                            `Disponible en ${
                                this.locationName(locationId)
                            }: ${
                                this.quantityDisplay(
                                    available,
                                    productId
                                )
                            }.`;
                        return;
                    }

                    const nextLine = {
                        catalog_product_id: String(productId),
                        source_location_id: String(locationId),
                        condition,
                        quantity: normalizedQuantity
                    };
                    const editingIndex =
                        this.editingProductIndex;
                    const mergeIndex =
                        this.productLines.findIndex(
                            (line, index) =>
                                index !== editingIndex
                                && this.productKey(line)
                                    === this.productKey(nextLine)
                        );

                    if (mergeIndex >= 0) {
                        const existing =
                            this.productLines[mergeIndex];
                        const mergedQuantity =
                            this.quantityNumber(
                                existing.quantity
                            )
                            + this.quantityNumber(
                                nextLine.quantity
                            );

                        this.productLines[mergeIndex] = {
                            ...existing,
                            quantity: this.normalizedQuantity(
                                mergedQuantity,
                                productId
                            )
                        };

                        if (editingIndex !== null) {
                            this.productLines.splice(
                                editingIndex,
                                1
                            );
                        }
                    } else if (editingIndex !== null) {
                        this.productLines.splice(
                            editingIndex,
                            1,
                            nextLine
                        );
                    } else {
                        this.productLines.push(nextLine);
                    }

                    this.resetProductComposer();
                },
                cartStep(productId) {
                    const scale = this.productScale(productId);

                    return scale === 0
                        ? 1
                        : Number(`0.${'0'.repeat(
                            Math.max(0, scale - 1)
                        )}1`);
                },
                cartLineAvailable(index) {
                    const line = this.productLines[index];

                    if (! line) {
                        return 0;
                    }

                    const raw = this.rawAvailableAt(
                        Number(line.catalog_product_id),
                        String(line.condition),
                        Number(line.source_location_id)
                    );

                    const committedElsewhere =
                        this.productLines.reduce(
                            (total, other, otherIndex) => {
                                if (
                                    otherIndex === index
                                    || this.productKey(other)
                                        !== this.productKey(line)
                                ) {
                                    return total;
                                }

                                return total + Math.max(
                                    0,
                                    this.quantityNumber(
                                        other.quantity
                                    )
                                );
                            },
                            0
                        );

                    return Math.max(
                        0,
                        raw - committedElsewhere
                    );
                },
                setCartQuantity(index, value) {
                    this.cartQuantityError = '';

                    const line = this.productLines[index];

                    if (! line) {
                        return false;
                    }

                    const productId =
                        Number(line.catalog_product_id);
                    const normalized =
                        this.normalizedQuantity(
                            value,
                            productId
                        );

                    if (normalized === null) {
                        this.cartQuantityError =
                            this.productScale(productId) === 0
                                ? 'La cantidad debe ser un entero mayor que cero.'
                                : 'La cantidad debe ser mayor que cero y respetar la escala del artículo.';
                        return false;
                    }

                    const requested =
                        this.quantityNumber(normalized);
                    const available =
                        this.cartLineAvailable(index);

                    if (requested > available) {
                        this.cartQuantityError =
                            `Disponible en ${
                                this.locationName(
                                    line.source_location_id
                                )
                            }: ${
                                this.quantityDisplay(
                                    available,
                                    productId
                                )
                            }.`;
                        return false;
                    }

                    this.productLines[index] = {
                        ...line,
                        quantity: normalized
                    };

                    return true;
                },
                adjustCartQuantity(index, direction) {
                    const line = this.productLines[index];

                    if (! line) {
                        return;
                    }

                    const productId =
                        Number(line.catalog_product_id);
                    const step =
                        this.cartStep(productId);
                    const current =
                        this.quantityNumber(line.quantity);
                    const next =
                        current + (Number(direction) * step);

                    if (next <= 0) {
                        this.cartQuantityError =
                            'Para retirar la línea completa usá Quitar.';
                        return;
                    }

                    this.setCartQuantity(
                        index,
                        this.productScale(productId) === 0
                            ? String(Math.trunc(next))
                            : next.toFixed(
                                this.productScale(productId)
                            )
                    );
                },
                editProduct(index) {
                    const line = this.productLines[index];

                    if (! line) {
                        return;
                    }

                    this.draftProduct = { ...line };
                    this.editingProductIndex = index;
                    this.articleQuery =
                        this.productLabel(
                            line.catalog_product_id
                        );
                    this.articleResultsOpen = false;
                    this.articleActiveIndex = -1;
                    this.productDraftError = '';

                    this.$nextTick(() => {
                        this.$refs.productComposer
                            ?.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        this.$refs.productQuantity?.focus();
                    });
                },
                removeProduct(index) {
                    this.productLines.splice(index, 1);

                    if (this.editingProductIndex === index) {
                        this.resetProductComposer();
                    } else if (
                        this.editingProductIndex !== null
                        && this.editingProductIndex > index
                    ) {
                        this.editingProductIndex--;
                    }
                },
                productLabel(productId) {
                    const product = this.productCatalog[
                        Number(productId)
                    ];

                    return product
                        ? `${product.sku} · ${product.name}`
                        : 'Producto';
                },
                conditionLabel(condition) {
                    return this.conditionLabels[condition]
                        ?? condition
                        ?? '';
                },
                locationName(locationId) {
                    return this.locationCatalog[
                        Number(locationId)
                    ] ?? 'Ubicación';
                },
                cartUnits() {
                    return this.productLines.reduce(
                        (total, line) =>
                            total
                            + Math.max(
                                0,
                                this.quantityNumber(line.quantity)
                            ),
                        0
                    );
                },
                serviceSummary() {
                    if (! this.serviceOrderId) {
                        return 'Venta sin reparación';
                    }

                    return this.serviceLabels[
                        Number(this.serviceOrderId)
                    ] ?? 'Reparación seleccionada';
                },
                customerSummary() {
                    if (
                        this.customerBusinessPartyId
                        && this.customerLabels[
                            Number(this.customerBusinessPartyId)
                        ]
                    ) {
                        return this.customerLabels[
                            Number(this.customerBusinessPartyId)
                        ];
                    }

                    const freeName = String(
                        this.customerName || ''
                    ).trim();

                    return freeName || 'Consumidor final';
                }
            }"
        >
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">

            <section class="sulu-card overflow-hidden">
                <button type="button" @click="setupOpen = ! setupOpen" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-amber-300">Orden a liquidar · Moneda</p>
                        <p class="mt-1 truncate text-sm font-semibold text-white">
                            <span x-text="serviceSummary()"></span><span class="text-slate-500"> · </span><span class="font-mono text-cyan-200" x-text="currencyCode"></span>
                        </p>
                    </div>
                    <span class="shrink-0 text-xs font-semibold text-cyan-300" x-text="setupOpen ? 'Cerrar' : 'Editar'"></span>
                </button>

                <div x-show="setupOpen" class="border-t border-slate-800 px-5 pb-5 pt-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-base font-bold text-white">Configuración de venta</h2>
                            <p class="mt-1 text-xs text-slate-500">La reparación es opcional; el precio técnico aprobado no puede editarse.</p>
                        </div>
                        <span class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-3 py-2 text-xs font-semibold text-emerald-200">{{ $unsettledOrders->count() }} pendiente{{ $unsettledOrders->count() === 1 ? '' : 's' }}</span>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1.5fr)_minmax(12rem,0.5fr)]">
                        <div>
                            <label for="service_order_id" class="text-sm font-semibold text-slate-200">Orden a liquidar</label>
                            <select id="service_order_id" name="service_order_id" x-model="serviceOrderId" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                                <option value="">Venta sin reparación</option>
                                @foreach($unsettledOrders as $serviceOrder)
                                    @php
                                        $quote = $serviceOrder->quotes->sortByDesc('revision')->first();
                                        $option = $quote?->decision?->selectedOption;
                                    @endphp
                                    <option value="{{ $serviceOrder->id }}" @selected((string) $selectedServiceId === (string) $serviceOrder->id)>
                                        #{{ $serviceOrder->order_number }} · {{ $serviceOrder->customer?->name ?? $serviceOrder->delivery?->recipient_name }} · {{ $serviceOrder->asset->brand_name }} {{ $serviceOrder->asset->model_name }} · $ {{ number_format(($option?->total_minor ?? 0) / 100, 2, ',', '.') }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="currency_code" class="text-sm font-semibold text-slate-200">Moneda</label>
                            <select id="currency_code" name="currency_code" x-model="currencyCode" required class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                                <option value="ARS">ARS · Pesos argentinos</option>
                                <option value="USD">USD · Dólares estadounidenses</option>
                            </select>
                        </div>
                    </div>

                    @if($selectedServiceOrder)
                        @php
                            $selectedQuote = $selectedServiceOrder->quotes
                                ->sortByDesc('revision')
                                ->first();
                            $selectedOption =
                                $selectedQuote?->decision?->selectedOption;
                        @endphp
                        <div class="mt-4 rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wider text-cyan-300">Presupuesto aprobado</p>
                                    <p class="mt-2 text-sm font-bold text-white">{{ $selectedOption?->label }}</p>
                                </div>
                                <p class="font-mono text-lg font-bold text-cyan-100">$ {{ number_format(($selectedOption?->total_minor ?? 0) / 100, 2, ',', '.') }}</p>
                            </div>
                            <div class="mt-3 space-y-2 border-t border-cyan-500/10 pt-3">
                                @foreach($selectedOption?->lines ?? [] as $line)
                                    <div class="flex justify-between gap-4 text-xs">
                                        <span class="text-slate-400">{{ $line->description }}</span>
                                        <span class="font-mono text-slate-200">$ {{ number_format($line->line_total_minor / 100, 2, ',', '.') }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 flex justify-end">
                        <button type="button" @click="setupOpen = false" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200">Listo</button>
                    </div>
                </div>
            </section>

            <section class="sulu-card overflow-hidden">
                <button type="button" @click="customerOpen = ! customerOpen" class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left">
                    <div class="min-w-0">
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-300">Cliente y referencia</p>
                        <p class="mt-1 truncate text-sm font-semibold text-white" x-text="customerSummary()"></p>
                    </div>
                    <span class="shrink-0 text-xs font-semibold text-cyan-300" x-text="customerOpen ? 'Cerrar' : 'Editar'"></span>
                </button>

                <div x-show="customerOpen" class="border-t border-slate-800 px-5 pb-5 pt-4">
                    <p class="text-xs text-slate-500">Puede utilizar una ficha existente o conservar una identificación libre de mostrador.</p>
                    <div class="mt-4">
                        <label for="customer_business_party_id" class="text-sm font-semibold text-slate-200">Cliente vinculado</label>
                        <select id="customer_business_party_id" name="customer_business_party_id" x-model="customerBusinessPartyId" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                            <option value="">Consumidor final o identificación libre</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" @selected((string) $selectedCustomerId === (string) $customer->id)>
                                    {{ $customer->name }}{{ $customer->tax_id ? ' · '.$customer->tax_id : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label for="customer_name" class="text-sm font-semibold text-slate-200">Nombre para la venta</label>
                            <input id="customer_name" name="customer_name" x-model="customerName" type="text" maxlength="255" value="{{ old('customer_name') }}" placeholder="Se deriva del cliente o receptor cuando queda vacío" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                        </div>
                        <div>
                            <label for="customer_document" class="text-sm font-semibold text-slate-200">Documento</label>
                            <input id="customer_document" name="customer_document" x-model="customerDocument" type="text" maxlength="255" value="{{ old('customer_document') }}" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">
                        </div>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <button type="button" @click="customerOpen = false" class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-semibold text-cyan-200">Listo</button>
                    </div>
                </div>
            </section>

            <section class="sulu-card p-5">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[0.18em] text-cyan-300">Carga central</p>
                        <h2 class="mt-1 text-lg font-bold text-white">Productos de la venta</h2>
                        <p class="mt-1 text-xs text-slate-500">Buscá por código o descripción y SRCM te guía solamente por condición y ubicación con disponibilidad real.</p>
                    </div>
                    <div class="text-right">
                        <p class="text-xs text-slate-500">
                            <strong class="text-slate-200" x-text="productLines.length"></strong>
                            líneas ·
                            <strong class="text-slate-200" x-text="quantityDisplay(cartUnits(), productLines[0]?.catalog_product_id)"></strong>
                            unidades
                        </p>
                        <p class="mt-1 font-mono text-lg font-bold text-cyan-200" x-text="`${currencyCode} ${money(productsSubtotalMinor())}`"></p>
                    </div>
                </div>

                <div
                    x-ref="productComposer"
                    data-sale-product-composer
                    data-sale-product-funnel
                    class="sticky top-16 z-20 mt-5 rounded-2xl border border-cyan-400/20 bg-slate-950/95 p-4 shadow-2xl shadow-slate-950/50 backdrop-blur"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-cyan-300" x-text="editingProductIndex === null ? 'Agregar producto' : 'Modificar producto'"></p>
                            <p class="mt-1 text-xs text-slate-600">Artículo → condición disponible → ubicación disponible → cantidad.</p>
                        </div>
                        <button
                            x-show="editingProductIndex !== null"
                            type="button"
                            @click="resetProductComposer()"
                            class="text-xs font-semibold text-slate-400 hover:text-white"
                        >Cancelar edición</button>
                    </div>

                    <div class="mt-4">
                        <label class="text-xs font-semibold text-slate-400">1 · Artículo</label>
                        <div
                            class="relative mt-2"
                            @click.outside="articleResultsOpen = false"
                        >
                            <input
                                x-ref="articleLookup"
                                x-model="articleQuery"
                                @input="articleQueryChanged()"
                                @focus="articleResultsOpen = Boolean(normalizeLookup(articleQuery))"
                                @keydown.arrow-down.prevent="moveArticleResult(1)"
                                @keydown.arrow-up.prevent="moveArticleResult(-1)"
                                @keydown.enter.prevent="commitArticleSearch()"
                                @keydown.escape.prevent="articleResultsOpen = false"
                                type="search"
                                autocomplete="off"
                                placeholder="SKU, código interno, descripción, marca, modelo…"
                                class="w-full rounded-xl border-slate-700 bg-slate-950 pr-24 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                            >
                            <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 rounded border border-slate-700 px-2 py-1 text-[10px] font-semibold text-slate-500">Enter</span>

                            <div
                                x-show="articleResultsOpen && articleMatches().length > 0"
                                class="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-xl border border-slate-700 bg-slate-950 shadow-2xl"
                            >
                                <template
                                    x-for="(product, resultIndex) in articleMatches()"
                                    :key="`lookup-${product.id}`"
                                >
                                    <button
                                        type="button"
                                        @mouseenter="articleActiveIndex = resultIndex"
                                        @click="selectArticle(product)"
                                        class="flex w-full items-center justify-between gap-4 border-b border-slate-800 px-4 py-3 text-left last:border-b-0"
                                        :class="articleActiveIndex === resultIndex ? 'bg-cyan-400/10' : 'hover:bg-slate-900'"
                                    >
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate text-sm font-semibold text-slate-100"
                                                x-text="`${product.sku} · ${product.name}`"
                                            ></span>
                                            <span
                                                class="mt-1 block truncate text-[11px] text-slate-500"
                                                x-text="articleResultMeta(product)"
                                            ></span>
                                        </span>
                                        <span
                                            class="shrink-0 text-right text-[11px] font-semibold"
                                            :class="companyAvailableAllConditions(product.id) > 0 ? 'text-emerald-300' : 'text-amber-300'"
                                            x-text="companyAvailableAllConditions(product.id) > 0
                                                ? `Disponible ${quantityDisplay(companyAvailableAllConditions(product.id), product.id)}`
                                                : 'Sin disponibilidad'"
                                        ></span>
                                    </button>
                                </template>
                            </div>

                            <div
                                x-show="articleResultsOpen && normalizeLookup(articleQuery) && articleMatches().length === 0"
                                class="absolute inset-x-0 top-full z-50 mt-2 rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-500 shadow-2xl"
                            >
                                Sin coincidencias. Podés buscar por SKU, identificador, descripción, marca o modelo relacionado.
                            </div>
                        </div>

                        <div
                            x-show="draftProduct.catalog_product_id"
                            class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900/50 px-3 py-2"
                        >
                            <p class="truncate text-xs font-semibold text-slate-300" x-text="productLabel(draftProduct.catalog_product_id)"></p>
                            <p
                                class="shrink-0 text-xs font-semibold"
                                :class="companyAvailableAllConditions(draftProduct.catalog_product_id) > 0 ? 'text-emerald-300' : 'text-amber-300'"
                            >
                                Disponible empresa:
                                <strong
                                    class="font-mono"
                                    x-text="quantityDisplay(
                                        companyAvailableAllConditions(
                                            draftProduct.catalog_product_id
                                        ),
                                        draftProduct.catalog_product_id
                                    )"
                                >0</strong>
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(12rem,0.9fr)_minmax(16rem,1.2fr)_minmax(8rem,0.55fr)]">
                        <div>
                            <label class="text-xs font-semibold text-slate-400">2 · Condición</label>
                            <select
                                x-ref="productCondition"
                                x-model="draftProduct.condition"
                                @change="conditionChanged()"
                                :disabled="!draftProduct.catalog_product_id || conditionOptions().length === 0"
                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 disabled:cursor-not-allowed disabled:opacity-40 focus:border-cyan-400 focus:ring-cyan-400"
                            >
                                <option
                                    value=""
                                    x-text="!draftProduct.catalog_product_id
                                        ? 'Primero seleccioná un artículo'
                                        : (conditionOptions().length === 0
                                            ? 'Sin condiciones disponibles'
                                            : 'Seleccionar condición')"
                                ></option>
                                <template
                                    x-for="option in conditionOptions()"
                                    :key="`condition-${option.value}`"
                                >
                                    <option
                                        :value="option.value"
                                        :label="`${option.label} (${quantityDisplay(option.available, draftProduct.catalog_product_id)})`"
                                        x-text="`${option.label} (${quantityDisplay(option.available, draftProduct.catalog_product_id)})`"
                                    ></option>
                                </template>
                            </select>
                            <p
                                x-show="draftProduct.condition"
                                class="mt-2 text-[11px] text-slate-500"
                            >
                                Disponible en
                                <span x-text="conditionLabel(draftProduct.condition)"></span>:
                                <strong
                                    class="font-mono text-slate-300"
                                    x-text="quantityDisplay(
                                        conditionAvailable(
                                            draftProduct.catalog_product_id,
                                            draftProduct.condition
                                        ),
                                        draftProduct.catalog_product_id
                                    )"
                                ></strong>
                            </p>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-400">3 · Ubicación de salida</label>
                            <select
                                x-ref="productLocation"
                                x-model="draftProduct.source_location_id"
                                @change="locationChanged()"
                                :disabled="!draftProduct.condition || locationOptions().length === 0"
                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 disabled:cursor-not-allowed disabled:opacity-40 focus:border-cyan-400 focus:ring-cyan-400"
                            >
                                <option
                                    value=""
                                    x-text="!draftProduct.condition
                                        ? 'Primero seleccioná una condición'
                                        : (locationOptions().length === 0
                                            ? 'Sin ubicaciones disponibles'
                                            : 'Seleccionar ubicación')"
                                ></option>
                                <template
                                    x-for="option in locationOptions()"
                                    :key="`location-${option.id}`"
                                >
                                    <option
                                        :value="option.id"
                                        :label="`${option.name} (${quantityDisplay(option.available, draftProduct.catalog_product_id)})`"
                                        x-text="`${option.name} (${quantityDisplay(option.available, draftProduct.catalog_product_id)})`"
                                    ></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-400">4 · Cantidad</label>
                            <input
                                x-ref="productQuantity"
                                x-model="draftProduct.quantity"
                                @keydown.enter.prevent="addOrUpdateProduct()"
                                :disabled="!draftProduct.source_location_id"
                                type="text"
                                inputmode="decimal"
                                placeholder="Ingresar"
                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 disabled:cursor-not-allowed disabled:opacity-40 focus:border-cyan-400 focus:ring-cyan-400"
                            >
                        </div>
                    </div>

                    <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(9rem,0.55fr)_minmax(9rem,0.55fr)_auto] lg:items-end">
                        <div>
                            <label class="text-xs font-semibold text-slate-400">Precio vigente</label>
                            <div
                                class="mt-2 min-h-[42px] rounded-xl border border-slate-800 bg-slate-900/70 px-3 py-2.5 font-mono text-sm font-bold"
                                :class="priceMinor(draftProduct.catalog_product_id) > 0 ? 'text-emerald-300' : 'text-amber-300'"
                                x-text="priceMinor(draftProduct.catalog_product_id) > 0 ? `${currencyCode} ${money(priceMinor(draftProduct.catalog_product_id))}` : 'Sin precio vigente'"
                            ></div>
                        </div>

                        <div>
                            <label class="text-xs font-semibold text-slate-400">Subtotal</label>
                            <div
                                class="mt-2 min-h-[42px] rounded-xl border border-cyan-400/10 bg-cyan-400/[0.04] px-3 py-2.5 font-mono text-sm font-bold text-cyan-200"
                                x-text="`${currencyCode} ${money(lineSubtotalMinor(draftProduct))}`"
                            ></div>
                        </div>

                        <button
                            type="button"
                            @click="addOrUpdateProduct()"
                            :disabled="!draftProduct.catalog_product_id
                                || !draftProduct.condition
                                || !draftProduct.source_location_id
                                || !String(draftProduct.quantity || '').trim()"
                            class="min-h-[42px] rounded-xl bg-cyan-400 px-5 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300 disabled:cursor-not-allowed disabled:opacity-40"
                            x-text="editingProductIndex === null ? 'Agregar' : 'Guardar cambios'"
                        ></button>
                    </div>

                    <p
                        x-show="productDraftError"
                        class="mt-3 rounded-xl border border-red-500/20 bg-red-500/10 px-3 py-2 text-xs font-semibold text-red-200"
                        x-text="productDraftError"
                    ></p>
                </div>

                <style data-sale-cart-paper-style>
    [data-sale-cart-paper-panel] {
        background:
            linear-gradient(180deg, rgba(255,255,255,1) 0%, rgba(248,250,252,1) 100%) !important;
        border-color: #cbd5e1 !important;
        box-shadow:
            0 18px 40px rgba(2, 6, 23, 0.22),
            0 1px 0 rgba(255,255,255,0.9) inset;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-header] {
        background: linear-gradient(180deg, #ffffff 0%, #f1f5f9 100%) !important;
        border-color: #cbd5e1 !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-title] {
        color: #0f172a !important;
        font-size: 0.95rem;
        letter-spacing: 0.02em;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-title]::after {
        content: 'Hoja operativa';
        display: inline-flex;
        margin-left: 0.65rem;
        padding: 0.18rem 0.55rem;
        border: 1px solid #cbd5e1;
        border-radius: 9999px;
        background: #e2e8f0;
        color: #475569;
        font-size: 0.62rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        vertical-align: middle;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-scroll-label] {
        color: #64748b !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-viewport] {
        background: #ffffff !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-table] {
        color: #0f172a !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-thead] {
        background: #e2e8f0 !important;
        color: #334155 !important;
        box-shadow: 0 1px 0 #cbd5e1;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-tbody] {
        border-color: #cbd5e1 !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row] {
        background: #ffffff !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row]:nth-child(even) {
        background: #f8fafc !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row]:hover {
        background: #eef6ff !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row] td {
        border-color: #dbe4ee !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row] p,
    [data-sale-cart-paper-panel] [data-sale-cart-row] td {
        color: #0f172a !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row] td:nth-child(5) {
        color: #047857 !important;
        font-weight: 700;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-row] td:nth-child(6) {
        color: #0f4c81 !important;
        font-weight: 800;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-summary] {
        background: #eef2f7 !important;
        border-color: #cbd5e1 !important;
        color: #0f172a !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-summary] * {
        color: #0f172a !important;
    }

    [data-sale-cart-paper-panel] button[data-sale-cart-edit] {
        color: #0369a1 !important;
    }

    [data-sale-cart-paper-panel] button[data-sale-cart-remove] {
        color: #b91c1c !important;
    }

    /* V1.3 — contraste definitivo del pie de la hoja operativa */
    [data-sale-cart-paper-panel] [data-sale-cart-summary] {
        background: #e7edf4 !important;
        border-color: #b8c5d3 !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-summary] > span:first-child {
        color: #334155 !important;
        font-weight: 750 !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-summary] > span:first-child * {
        color: #334155 !important;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-summary] > span:last-child {
        color: #0f3d5e !important;
        font-weight: 900 !important;
        text-shadow: none !important;
    }

    /* Cantidad inline — control operativo compacto */
    [data-sale-cart-paper-panel] [data-sale-cart-quantity-control] {
        min-width: 8.75rem;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-input] {
        width: 4.2rem;
        height: 2rem;
        border: 1px solid #94a3b8;
        border-radius: 0.5rem;
        background: #ffffff;
        color: #0f172a !important;
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-size: 0.8rem;
        font-weight: 800;
        text-align: center;
        outline: none;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-input]:focus {
        border-color: #0284c7;
        box-shadow: 0 0 0 2px rgba(2, 132, 199, 0.14);
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-minus],
    [data-sale-cart-paper-panel] [data-sale-cart-quantity-plus] {
        display: inline-flex;
        width: 2rem;
        height: 2rem;
        align-items: center;
        justify-content: center;
        border: 1px solid #94a3b8;
        border-radius: 0.5rem;
        background: #f8fafc;
        color: #0f3d5e !important;
        font-family: ui-sans-serif, system-ui, sans-serif;
        font-size: 1rem;
        font-weight: 900;
        line-height: 1;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-minus]:hover:not(:disabled),
    [data-sale-cart-paper-panel] [data-sale-cart-quantity-plus]:hover:not(:disabled) {
        background: #e0f2fe;
        border-color: #0284c7;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-minus]:disabled,
    [data-sale-cart-paper-panel] [data-sale-cart-quantity-plus]:disabled {
        cursor: not-allowed;
        opacity: 0.35;
    }

    [data-sale-cart-paper-panel] [data-sale-cart-quantity-error] {
        color: #b91c1c !important;
    }
</style>
                <div data-sale-cart-paper-panel class="mt-5 overflow-hidden rounded-xl border border-slate-800">
                    <div data-sale-cart-header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 bg-slate-950/70 px-4 py-3">
                        <p data-sale-cart-title class="text-sm font-bold text-white">Carrito</p>
                        <p data-sale-cart-scroll-label class="text-xs text-slate-500">Scroll interno para ventas extensas</p>
                    </div>

                    <div data-sale-cart-viewport class="max-h-[22rem] overflow-auto">
                        <table data-sale-cart-table class="min-w-full text-left text-xs">
                            <thead data-sale-cart-thead class="sticky top-0 z-10 bg-slate-900 text-slate-400">
                                <tr>
                                    <th class="px-3 py-2.5 font-semibold">Artículo</th>
                                    <th class="px-3 py-2.5 font-semibold">Condición</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Cant.</th>
                                    <th class="px-3 py-2.5 font-semibold">Ubicación</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Precio</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Subtotal</th>
                                    <th class="px-3 py-2.5 text-right font-semibold">Acciones</th>
                                </tr>
                            </thead>
                            <tbody data-sale-cart-tbody class="divide-y divide-slate-800">
                                <template x-if="productLines.length === 0">
                                    <tr>
                                        <td colspan="7" class="px-4 py-8 text-center text-sm text-slate-600">Todavía no agregaste productos a la venta.</td>
                                    </tr>
                                </template>

                                <template x-for="(line, index) in productLines" :key="`cart-${index}-${productKey(line)}`">
                                    <tr data-sale-cart-row class="bg-slate-950/40 align-middle hover:bg-slate-900/60">
                                        <td class="max-w-[20rem] px-3 py-3">
                                            <input type="hidden" :name="`product_lines[${index}][catalog_product_id]`" :value="line.catalog_product_id">
                                            <input type="hidden" :name="`product_lines[${index}][source_location_id]`" :value="line.source_location_id">
                                            <input type="hidden" :name="`product_lines[${index}][condition]`" :value="line.condition">
                                            <input type="hidden" :name="`product_lines[${index}][quantity]`" :value="line.quantity">
                                            <p class="truncate font-semibold text-slate-100" x-text="productLabel(line.catalog_product_id)"></p>
                                        </td>
                                        <td class="px-3 py-3 text-slate-300" x-text="conditionLabel(line.condition)"></td>
                                        <td data-sale-cart-quantity-control class="px-3 py-3 text-right font-mono text-slate-200">
    <div class="inline-flex items-center justify-end gap-1.5">
        <button
            data-sale-cart-quantity-minus
            type="button"
            @click="adjustCartQuantity(index, -1)"
            :disabled="quantityNumber(line.quantity) <= cartStep(line.catalog_product_id)"
            aria-label="Restar cantidad"
        >−</button>
        <input
            data-sale-cart-quantity-input
            type="number"
            inputmode="decimal"
            :step="cartStep(line.catalog_product_id)"
            :min="cartStep(line.catalog_product_id)"
            :max="cartLineAvailable(index)"
            :value="line.quantity"
            @focus="cartQuantityError = ''"
            @keydown.enter.prevent="$event.target.blur()"
            @change="
                if (! setCartQuantity(index, $event.target.value)) {
                    $event.target.value = line.quantity;
                } else {
                    $event.target.value = productLines[index].quantity;
                }
            "
            aria-label="Cantidad del artículo"
        >
        <button
            data-sale-cart-quantity-plus
            type="button"
            @click="adjustCartQuantity(index, 1)"
            :disabled="quantityNumber(line.quantity) >= cartLineAvailable(index)"
            aria-label="Sumar cantidad"
        >+</button>
    </div>
</td>
                                        <td class="px-3 py-3 text-slate-300" x-text="locationName(line.source_location_id)"></td>
                                        <td class="px-3 py-3 text-right font-mono text-emerald-300" x-text="`${currencyCode} ${money(priceMinor(line.catalog_product_id))}`"></td>
                                        <td class="px-3 py-3 text-right font-mono font-bold text-cyan-200" x-text="`${currencyCode} ${money(lineSubtotalMinor(line))}`"></td>
                                        <td class="whitespace-nowrap px-3 py-3 text-right">
                                            <button data-sale-cart-edit type="button" @click="editProduct(index)" class="font-semibold text-cyan-300 hover:text-cyan-200">Editar</button>
                                            <span class="mx-1 text-slate-700">·</span>
                                            <button data-sale-cart-remove type="button" @click="removeProduct(index)" class="font-semibold text-red-300 hover:text-red-200">Quitar</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <p
    data-sale-cart-quantity-error
    x-show="cartQuantityError"
    x-cloak
    class="border-t border-red-200 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700"
    x-text="cartQuantityError"
></p>
                    <div data-sale-cart-summary class="flex flex-wrap items-center justify-between gap-3 border-t border-cyan-400/15 bg-cyan-400/[0.04] px-4 py-3">
                        <span class="text-sm font-semibold text-slate-300">
                            <strong x-text="productLines.length"></strong> líneas ·
                            <strong x-text="quantityDisplay(cartUnits(), productLines[0]?.catalog_product_id)"></strong> unidades
                        </span>
                        <span class="font-mono text-lg font-bold text-cyan-200" x-text="`${currencyCode} ${money(productsSubtotalMinor())}`"></span>
                    </div>
                </div>
            </section>

            <section class="sulu-card p-6">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <p class="text-sm font-semibold text-slate-200">Momento de venta</p>
                        <div class="mt-2 rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-slate-300">
                            Se registra al confirmar, usando el reloj del servidor.
                        </div>
                        <p class="mt-2 text-xs text-slate-500">
                            La operación normal no permite fechar la venta manualmente.
                        </p>
                    </div>
                    <div>
                        <label for="notes" class="text-sm font-semibold text-slate-200">Notas internas</label>
                        <textarea id="notes" name="notes" rows="3" maxlength="5000" class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400">{{ old('notes') }}</textarea>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-xs leading-5 text-amber-100">
                    La confirmación es inmutable. Si falla el stock, la identidad, la moneda o la liquidación exacta entre pagos y saldo pendiente, toda la operación se revierte.
                </div>

                <div class="mt-5 flex flex-wrap justify-end gap-3 border-t border-slate-800 pt-5">
                    <a href="{{ route('commerce-sales.index') }}" class="rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white">Cancelar</a>
                    <button
                        type="button"
                        @click="openPaymentOverlay()"
                        class="rounded-xl bg-emerald-400 px-5 py-2.5 text-sm font-black text-slate-950 transition hover:bg-emerald-300"
                        data-sale-payment-launcher
                    >
                        Abrir cobro · F7
                    </button>
                </div>
            </section>

            <style data-sale-payment-overlay-style>
                [x-cloak] { display: none !important; }
            </style>

            <div
                x-cloak
                x-show="paymentOverlayOpen"
                x-transition.opacity
                class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/85 p-3 backdrop-blur-sm sm:p-6"
                data-sale-payment-overlay
                role="dialog"
                aria-modal="true"
                aria-label="Terminal de Cobro"
                @keydown.escape.prevent.stop="backPaymentLevel()"
            >
                <div class="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-3xl border border-emerald-400/30 bg-slate-900 shadow-2xl shadow-black/60">
                    <header class="border-b border-slate-700 bg-slate-950/80 px-5 py-4 sm:px-7">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-black uppercase tracking-[0.24em] text-emerald-300">Terminal de Cobro · F7</p>
                                <h2 class="mt-1 text-xl font-black text-white sm:text-2xl">Culminación de la venta</h2>
                                <p class="mt-1 text-xs text-slate-400">Venta visible detrás, bloqueada hasta volver o confirmar.</p>
                            </div>
                            <button
                                type="button"
                                @click="backPaymentLevel()"
                                class="rounded-xl border border-slate-600 px-4 py-2 text-sm font-bold text-slate-200 transition hover:border-slate-400 hover:text-white"
                                data-sale-payment-back
                            >
                                <span x-text="paymentReviewOpen ? 'Volver al cobro' : 'Volver a venta'"></span>
                                <span class="ml-1 text-[11px] font-mono text-slate-500">Esc</span>
                            </button>
                        </div>
                    </header>

                    <div class="overflow-y-auto">
                        <div x-show="! paymentReviewOpen" class="space-y-6 p-5 sm:p-7">
                            <section
                                class="rounded-2xl border px-4 py-3"
                                data-sale-cash-session-context
                                :class="cashSessionAvailable()
                                    ? 'border-cyan-400/25 bg-cyan-400/5'
                                    : 'border-slate-700 bg-slate-950/45'"
                            >
                                <template x-if="cashSessionAvailable()">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[0.18em] text-cyan-300">Turno de caja activo</p>
                                        <p class="mt-1 text-sm font-bold text-white">
                                            <span x-text="activeCashSession.register_name"></span>
                                            <span class="text-slate-500"> → </span>
                                            <span x-text="activeCashSession.financial_account_label"></span>
                                        </p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Al elegir Efectivo, SRCM deriva este destino automáticamente.
                                        </p>
                                    </div>
                                </template>
                                <template x-if="! cashSessionAvailable()">
                                    <div>
                                        <p class="text-sm font-black text-slate-300">Sin turno de caja compatible con la moneda.</p>
                                        <p class="mt-1 text-xs text-slate-500">Efectivo requiere un turno de caja abierto; los medios electrónicos siguen disponibles.</p>
                                    </div>
                                </template>
                            </section>

                            <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,0.55fr)]">
                                <div class="rounded-2xl border border-emerald-400/25 bg-emerald-400/5 p-5">
                                    <p class="text-xs font-black uppercase tracking-[0.2em] text-emerald-300">Total a cobrar</p>
                                    <div class="mt-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                        <span class="font-mono text-lg font-bold text-slate-400" x-text="currencyCode"></span>
                                        <span class="font-mono text-4xl font-black tracking-tight text-white sm:text-5xl" x-text="money(saleTotalMinor())"></span>
                                    </div>
                                    <p
                                        x-show="! serviceCurrencyMatches()"
                                        class="mt-3 rounded-xl border border-red-400/30 bg-red-400/10 px-3 py-2 text-sm font-bold text-red-200"
                                    >
                                        La moneda de la venta no coincide con el presupuesto aprobado. Corregila antes de cobrar.
                                    </p>
                                </div>

                                <div
                                    class="rounded-2xl border p-5"
                                    data-sale-payment-reconciliation
                                    :class="{
                                        'border-emerald-400/40 bg-emerald-400/10': paymentStatus() === 'exact',
                                        'border-amber-400/40 bg-amber-400/10': paymentStatus() === 'short' || paymentStatus() === 'incomplete',
                                        'border-red-400/40 bg-red-400/10': paymentStatus() === 'excess' || paymentStatus() === 'currency',
                                        'border-slate-700 bg-slate-950/50': paymentStatus() === 'empty'
                                    }"
                                >
                                    <div class="flex items-center justify-between gap-3 text-sm">
                                        <span class="font-bold text-slate-400">Pagos cargados</span>
                                        <strong class="font-mono text-lg text-white" x-text="`${currencyCode} ${money(paymentTotalMinor())}`"></strong>
                                    </div>
                                    <div class="mt-3 border-t border-white/10 pt-3">
                                        <p x-show="paymentStatus() === 'exact'" class="text-lg font-black text-emerald-200">✓ PAGO EXACTO</p>
                                        <p x-show="paymentStatus() === 'short'" class="text-lg font-black text-amber-200">
                                            FALTA <span class="font-mono" x-text="`${currencyCode} ${money(paymentStatusAmountMinor())}`"></span>
                                        </p>
                                        <p x-show="paymentStatus() === 'excess'" class="text-lg font-black text-red-200">
                                            EXCEDE <span class="font-mono" x-text="`${currencyCode} ${money(paymentStatusAmountMinor())}`"></span>
                                        </p>
                                        <p x-show="paymentStatus() === 'incomplete'" class="text-lg font-black text-amber-200">
                                            FALTAN DATOS DEL PAGO
                                        </p>
                                        <p x-show="paymentStatus() === 'empty'" class="text-sm font-bold text-slate-400">Elegí cómo paga el cliente.</p>
                                        <p x-show="paymentStatus() === 'currency'" class="text-sm font-black text-red-200">REVISAR MONEDA</p>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border border-slate-700 bg-slate-950/45 p-5" data-sale-payment-method-picker>
                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div>
                                        <p class="text-xs font-black uppercase tracking-[0.2em] text-cyan-300">¿Cómo paga el cliente?</p>
                                        <h3 class="mt-1 text-lg font-black text-white">Elegí el medio explícitamente</h3>
                                        <p class="mt-1 text-xs text-slate-400">SRCM no presupone Efectivo. Para pago combinado agregá más de un medio.</p>
                                    </div>
                                    <button
                                        x-show="payments.length > 0"
                                        type="button"
                                        @click="adjustLastPaymentToBalance()"
                                        class="rounded-xl border border-cyan-400/30 px-4 py-2 text-sm font-bold text-cyan-200 transition hover:border-cyan-300"
                                    >
                                        Ajustar último al saldo
                                    </button>
                                </div>

                                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                    @foreach($paymentMethods as $method)
                                        <button
                                            type="button"
                                            @click="addPayment('{{ $method->value }}')"
                                            class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-left text-sm font-bold text-slate-100 transition hover:border-emerald-400/50 hover:bg-emerald-400/10"
                                            data-sale-payment-method="{{ $method->value }}"
                                        >
                                            {{ $method->label() }}
                                        </button>
                                    @endforeach
                                </div>
                            </section>

                            @if($canCreateCustomerReceivable)
                                <section
                                    class="rounded-2xl border border-amber-400/25 bg-amber-400/[0.06] p-5"
                                    data-sale-customer-receivable
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-black uppercase tracking-[0.18em] text-amber-300">Saldo pendiente / cuenta corriente</p>
                                            <p class="mt-1 text-xs leading-5 text-slate-400">No es un cobro. Registra cuánto queda debiendo el cliente después de los pagos recibidos.</p>
                                        </div>
                                        <span class="rounded-lg border border-amber-400/20 px-2.5 py-1 text-[10px] font-black uppercase tracking-wide text-amber-200">Administrador</span>
                                    </div>

                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="receivable_amount" class="text-xs font-bold text-slate-400">Importe pendiente</label>
                                            <input
                                                id="receivable_amount"
                                                name="receivable_amount"
                                                x-model="receivableAmount"
                                                @input="paymentReviewOpen = false; paymentError = ''; syncAutomaticPayment()"
                                                type="text"
                                                inputmode="decimal"
                                                placeholder="0,00"
                                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-lg font-black text-white focus:border-amber-400 focus:ring-amber-400"
                                            >
                                        </div>
                                        <div>
                                            <label for="receivable_due_on" class="text-xs font-bold text-slate-400">Vencimiento opcional</label>
                                            <input
                                                id="receivable_due_on"
                                                name="receivable_due_on"
                                                x-model="receivableDueOn"
                                                @change="paymentReviewOpen = false; paymentError = ''"
                                                type="date"
                                                min="{{ now()->toDateString() }}"
                                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-amber-400 focus:ring-amber-400"
                                            >
                                        </div>
                                    </div>

                                    <p
                                        x-show="receivableAmountMinor() > 0 && ! customerBusinessPartyId"
                                        class="mt-3 text-xs font-bold text-red-300"
                                    >
                                        Vinculá un cliente antes de dejar saldo pendiente.
                                    </p>
                                    <p
                                        x-show="receivableAmountMinor() > 0"
                                        class="mt-3 font-mono text-sm font-black text-amber-100"
                                        x-text="`Pendiente: ${currencyCode} ${money(receivableAmountMinor())}`"
                                    ></p>
                                </section>
                            @endif

                            <section class="space-y-4">
                                <div
                                x-show="financialAccountOptions().length === 0"
                                x-cloak
                                class="mb-4 rounded-xl border border-amber-400/30 bg-amber-400/10 px-4 py-3 text-sm font-bold text-amber-100"
                                data-sale-payment-no-financial-accounts
                            >
                                No hay cuentas financieras activas para la moneda seleccionada. Un administrador debe configurar una cuenta antes de confirmar el cobro.
                            </div>

                            <template x-for="(payment, index) in payments" :key="'payment-overlay-'+index">
                                    <article class="rounded-2xl border border-slate-700 bg-slate-950/55 p-5" data-sale-payment-card>
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-black uppercase tracking-[0.18em] text-slate-500">Pago <span x-text="index + 1"></span></p>
                                                <p class="mt-1 text-lg font-black text-white" x-text="paymentMethodLabel(payment.method)"></p>
                                            </div>
                                            <button
                                                type="button"
                                                @click="removePayment(index)"
                                                class="rounded-lg border border-red-400/20 px-3 py-2 text-xs font-bold text-red-300 hover:border-red-300"
                                            >
                                                Quitar
                                            </button>
                                        </div>

                                        <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                                            <div>
                                                <label class="text-xs font-bold text-slate-400">Medio</label>
                                                <select
                                                    :name="`payments[${index}][method]`"
                                                    x-model="payment.method"
                                                    @change="onPaymentMethodChanged(payment)"
                                                    required
                                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"
                                                >
                                                    <option value="">Seleccionar medio…</option>
                                                    @foreach($paymentMethods as $method)
                                                        <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <div data-sale-payment-financial-account>
                                                <label class="text-xs font-bold text-slate-400">Cuenta destino</label>

                                                <input
                                                    type="hidden"
                                                    :name="payment.method === 'cash' ? `payments[${index}][financial_account_id]` : null"
                                                    :value="payment.financial_account_id"
                                                >

                                                <template x-if="payment.method === 'cash'">
                                                    <div>
                                                        <div
                                                            data-sale-cash-derived-account
                                                            class="mt-2 w-full rounded-xl border border-cyan-400/25 bg-cyan-400/5 px-3 py-2.5 text-sm font-bold text-cyan-100"
                                                            x-text="cashSessionAvailable()
                                                                ? activeCashSession.financial_account_label
                                                                : 'Sin turno de caja compatible'"
                                                        ></div>
                                                        <p class="mt-1 text-[11px] font-semibold text-cyan-300">
                                                            Destino derivado del turno abierto. No editable en cobro normal.
                                                        </p>
                                                    </div>
                                                </template>

                                                <template x-if="payment.method === 'account_credit'">
                                                    <div class="mt-2 rounded-xl border border-emerald-400/25 bg-emerald-400/5 px-3 py-2.5">
                                                        <p class="text-[10px] font-black uppercase tracking-wider text-emerald-300">Saldo disponible</p>
                                                        <p class="mt-1 font-mono text-sm font-black text-emerald-100" x-text="`${currencyCode} ${money(customerCreditMinor())}`"></p>
                                                        <p class="mt-1 text-[11px] text-slate-500">Derivado de grants append-only menos consumos previos. Sin cuenta financiera.</p>
                                                    </div>
                                                </template>

                                                <template x-if="payment.method !== 'cash' && payment.method !== 'account_credit'">
                                                    <div>
                                                        <select
                                                            :name="`payments[${index}][financial_account_id]`"
                                                            x-model="payment.financial_account_id"
                                                            @change="paymentReviewOpen = false; paymentError = ''"
                                                            required
                                                            class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                        >
                                                            <option value="">Seleccionar cuenta…</option>
                                                            <template
                                                                x-for="account in financialAccountOptions()"
                                                                :key="`financial-account-${account.id}`"
                                                            >
                                                                <option
                                                                    :value="String(account.id)"
                                                                    x-text="account.label"
                                                                ></option>
                                                            </template>
                                                        </select>
                                                        <p class="mt-1 text-[11px] text-slate-500">
                                                            Destino declarado. No equivale a acreditación ni conciliación.
                                                        </p>
                                                    </div>
                                                </template>
                                            </div>

                                            <div>
                                                <label class="text-xs font-bold text-slate-400">Importe aplicado</label>
                                                <input
                                                    :name="`payments[${index}][amount]`"
                                                    x-model="payment.amount"
                                                    @input="markPaymentAmountManual(payment)"
                                                    @keydown.enter.prevent="$event.target.blur()"
                                                    type="text"
                                                    inputmode="decimal"
                                                    placeholder="0,00"
                                                    required
                                                    data-sale-payment-amount
                                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-lg font-black text-white focus:border-emerald-400 focus:ring-emerald-400"
                                                >
                                                <p x-show="payment._manual === false" class="mt-1 text-[11px] font-semibold text-cyan-300">Auto: sigue el total hasta que lo edites.</p>
                                                <p x-show="payment._manual !== false" class="mt-1 text-[11px] text-slate-500">Manual: SRCM no lo reescribe silenciosamente.</p>
                                            </div>

                                            <div x-show="payment.method === 'cash'" x-cloak data-sale-cash-tender>
                                                <label class="text-xs font-bold text-slate-400">Dinero entregado</label>
                                                <input
                                                    :name="payment.method === 'cash' ? `payments[${index}][tendered_amount]` : null"
                                                    x-model="payment.tendered_amount"
                                                    @input="markPaymentTenderManual(payment)"
                                                    @keydown.enter.prevent="$event.target.blur()"
                                                    type="text"
                                                    inputmode="decimal"
                                                    placeholder="0,00"
                                                    :required="payment.method === 'cash'"
                                                    data-sale-cash-tendered-amount
                                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-lg font-black text-white focus:border-emerald-400 focus:ring-emerald-400"
                                                >
                                                <p
                                                    x-show="cashTenderValid(payment)"
                                                    class="mt-1 text-[11px] font-bold text-emerald-300"
                                                    data-sale-cash-change
                                                >
                                                    Vuelto: <span x-text="`${currencyCode} ${money(paymentChangeMinor(payment))}`"></span>
                                                </p>
                                                <p
                                                    x-show="cashTenderedMinor(payment) > 0 && ! cashTenderValid(payment)"
                                                    class="mt-1 text-[11px] font-bold text-red-300"
                                                >
                                                    El dinero entregado no alcanza el importe aplicado.
                                                </p>
                                                <p x-show="payment._tenderManual !== true" class="mt-1 text-[11px] text-cyan-300">Auto: sigue el importe aplicado hasta que lo edites.</p>
                                                <p x-show="payment._tenderManual === true" class="mt-1 text-[11px] text-slate-500">Manual: SRCM no lo reescribe silenciosamente.</p>
                                            </div>

                                            <div>
                                                <label class="text-xs font-bold text-slate-400">Referencia</label>
                                                <input
                                                    :name="payment.method !== 'account_credit' ? `payments[${index}][reference]` : null"
                                                    x-model="payment.reference"
                                                    @input="paymentReviewOpen = false; paymentError = ''"
                                                    type="text"
                                                    maxlength="255"
                                                    :required="paymentRequiresReference(payment.method)"
                                                    :disabled="payment.method === 'account_credit'"
                                                    :placeholder="payment.method === 'account_credit' ? 'Generada por SRCM al consumir saldo' : (paymentRequiresReference(payment.method) ? 'Obligatoria para este medio' : 'Opcional en efectivo')"
                                                    class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"
                                                >
                                            </div>

                                            <div>
                                                <p class="text-xs font-bold text-slate-400">Hora efectiva del cobro</p>
                                                <div class="mt-2 rounded-xl border border-slate-700 bg-slate-950 px-3 py-2.5 text-sm text-slate-300">
                                                    Se registra al confirmar
                                                </div>
                                                <p class="mt-1 text-[11px] text-slate-500">
                                                    No editable en el cobro operativo normal.
                                                </p>
                                            </div>
                                        </div>

                                        <div
                                            x-show="payment.method && payment.method !== 'cash' && payment.method !== 'account_credit'"
                                            x-cloak
                                            class="mt-4 rounded-2xl border border-cyan-400/20 bg-cyan-400/[0.04] p-4"
                                            data-sale-payment-structured-evidence
                                        >
                                            <div data-sale-payment-evidence-source-guidance>
                                                <div class="flex flex-wrap items-start justify-between gap-3">
                                                    <div>
                                                        <p class="text-xs font-black uppercase tracking-[0.18em] text-cyan-300">Evidencia del cobro</p>
                                                        <p class="mt-1 text-xs text-slate-400">Snapshot declarado al cobrar. No significa acreditado ni conciliado.</p>
                                                    </div>
                                                    <span class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-1.5 text-[11px] font-bold text-slate-400">Nunca PAN/CVV</span>
                                                </div>

                                                <div class="mt-4 grid gap-3 md:grid-cols-2">
                                                    <div class="rounded-xl border border-emerald-400/20 bg-emerald-400/[0.05] p-4">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <p class="text-[11px] font-black uppercase tracking-[0.16em] text-emerald-300">Automática / API</p>
                                                            <span class="rounded-md border border-amber-400/25 bg-amber-400/10 px-2 py-1 text-[10px] font-black uppercase tracking-wide text-amber-200">Pendiente de integración</span>
                                                        </div>
                                                        <p class="mt-2 text-xs leading-5 text-slate-400">
                                                            Cuando el procesador esté conectado, SRCM completará la metadata segura automáticamente y la mostrará como evidencia de solo lectura. El operador no deberá transcribirla.
                                                        </p>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-700 bg-slate-950/55 p-4">
                                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                                            <p class="text-[11px] font-black uppercase tracking-[0.16em] text-slate-300">Respaldo manual</p>
                                                            <span class="rounded-md border border-cyan-400/25 bg-cyan-400/10 px-2 py-1 text-[10px] font-black uppercase tracking-wide text-cyan-200">Disponible hoy</span>
                                                        </div>
                                                        <p class="mt-2 text-xs leading-5 text-slate-400">
                                                            Usalo solamente cuando el proveedor o la entidad no pueda consultarse automáticamente. Lo informado quedará como snapshot declarado del cobro.
                                                        </p>
                                                    </div>
                                                </div>

                                                <div class="mt-4 flex items-center gap-2 border-t border-cyan-400/10 pt-4">
                                                    <span class="h-2 w-2 rounded-full bg-cyan-300"></span>
                                                    <p class="text-[11px] font-black uppercase tracking-[0.15em] text-cyan-200">Carga manual de respaldo</p>
                                                </div>
                                            </div>

                                            <div
                                                x-show="paymentIsCard(payment.method)"
                                                class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                                            >
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Marca</label>
                                                    <input
                                                        :name="`payments[${index}][card_brand]`"
                                                        x-model="payment.card_brand"
                                                        type="text"
                                                        maxlength="50"
                                                        placeholder="Visa, Mastercard…"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Red</label>
                                                    <input
                                                        :name="`payments[${index}][card_network]`"
                                                        x-model="payment.card_network"
                                                        type="text"
                                                        maxlength="50"
                                                        placeholder="Red informada"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Últimos 4</label>
                                                    <input
                                                        :name="`payments[${index}][card_last4]`"
                                                        x-model="payment.card_last4"
                                                        type="text"
                                                        inputmode="numeric"
                                                        maxlength="4"
                                                        pattern="[0-9]{4}"
                                                        placeholder="4242"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Cuotas</label>
                                                    <input
                                                        :name="`payments[${index}][installments]`"
                                                        x-model="payment.installments"
                                                        type="number"
                                                        min="1"
                                                        max="120"
                                                        step="1"
                                                        placeholder="1"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                            </div>

                                            <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Procesador / proveedor</label>
                                                    <input
                                                        :name="`payments[${index}][processor]`"
                                                        x-model="payment.processor"
                                                        type="text"
                                                        maxlength="100"
                                                        placeholder="Mercado Pago, Payway…"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Operación externa</label>
                                                    <input
                                                        :name="`payments[${index}][external_operation_id]`"
                                                        x-model="payment.external_operation_id"
                                                        type="text"
                                                        maxlength="191"
                                                        placeholder="Operación #…"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Autorización</label>
                                                    <input
                                                        :name="`payments[${index}][authorization_code]`"
                                                        x-model="payment.authorization_code"
                                                        type="text"
                                                        maxlength="100"
                                                        placeholder="Código opcional"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 font-mono text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                                <div>
                                                    <label class="text-xs font-bold text-slate-400">Estado informado</label>
                                                    <input
                                                        :name="`payments[${index}][provider_status]`"
                                                        x-model="payment.provider_status"
                                                        type="text"
                                                        maxlength="50"
                                                        placeholder="approved, pending…"
                                                        class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-cyan-400 focus:ring-cyan-400"
                                                    >
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mt-4">
                                            <label class="text-xs font-bold text-slate-400">Notas internas del cobro</label>
                                            <input
                                                :name="`payments[${index}][notes]`"
                                                x-model="payment.notes"
                                                type="text"
                                                maxlength="2000"
                                                placeholder="Observación operativa opcional."
                                                class="mt-2 w-full rounded-xl border-slate-700 bg-slate-950 text-slate-100 focus:border-emerald-400 focus:ring-emerald-400"
                                            >
                                        </div>
                                    </article>
                                </template>

                                <div
                                    x-show="payments.length === 0"
                                    class="rounded-2xl border border-dashed border-slate-700 px-5 py-8 text-center"
                                >
                                    <p class="text-sm font-bold text-slate-300">Todavía no hay ningún medio preparado.</p>
                                    <p class="mt-1 text-xs text-slate-500">Preguntá al cliente y elegí arriba: Efectivo, Transferencia, Tarjeta, etc.</p>
                                </div>
                            </section>

                            <p
                                x-show="paymentError"
                                x-text="paymentError"
                                class="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm font-bold text-red-100"
                                data-sale-payment-error
                            ></p>

                            <footer class="sticky bottom-0 -mx-5 -mb-5 border-t border-slate-700 bg-slate-900/95 px-5 py-4 backdrop-blur sm:-mx-7 sm:-mb-7 sm:px-7">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <button
                                        type="button"
                                        @click="backPaymentLevel()"
                                        class="rounded-xl border border-slate-600 px-5 py-3 text-sm font-bold text-slate-200"
                                        data-sale-payment-back
                                    >
                                        Volver a venta <span class="ml-1 text-[11px] font-mono text-slate-500">Esc</span>
                                    </button>
                                    <button
                                        type="button"
                                        @click="openPaymentReview()"
                                        :disabled="! paymentExact()"
                                        class="rounded-xl bg-emerald-400 px-6 py-3 text-sm font-black text-slate-950 transition enabled:hover:bg-emerald-300 disabled:cursor-not-allowed disabled:opacity-40"
                                        data-sale-payment-review-button
                                    >
                                        Revisar y confirmar cobro
                                    </button>
                                </div>
                            </footer>
                        </div>

                        <div
                            x-show="paymentReviewOpen"
                            class="space-y-6 p-5 sm:p-8"
                            data-sale-payment-review
                        >
                            <div class="mx-auto max-w-3xl text-center">
                                <p class="text-xs font-black uppercase tracking-[0.24em] text-amber-300">Confirmación final</p>
                                <h3 class="mt-3 text-2xl font-black text-white sm:text-3xl">CONFIRMAR VENTA</h3>
                                <p class="mt-4 font-mono text-5xl font-black tracking-tight text-white">
                                    <span class="text-xl text-slate-400" x-text="currencyCode"></span>
                                    <span x-text="money(saleTotalMinor())"></span>
                                </p>

                                <div class="mt-6 space-y-3 text-left">
                                    <template x-for="(payment, index) in payments" :key="'review-payment-'+index">
                                        <div class="rounded-2xl border border-slate-700 bg-slate-950/60 p-4">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <strong class="text-lg text-white" x-text="paymentMethodLabel(payment.method)"></strong>
                                                <strong class="font-mono text-xl text-emerald-200" x-text="`${currencyCode} ${money(paymentAmountMinor(payment.amount))}`"></strong>
                                            </div>
                                            <div
                                                x-show="payment.method === 'cash'"
                                                class="mt-3 grid gap-2 rounded-xl border border-emerald-400/20 bg-emerald-400/[0.04] p-3 text-sm sm:grid-cols-3"
                                                data-sale-cash-tender-review
                                            >
                                                <p class="text-slate-300">Aplicado: <strong class="font-mono text-white" x-text="`${currencyCode} ${money(paymentAmountMinor(payment.amount))}`"></strong></p>
                                                <p class="text-slate-300">Entregado: <strong class="font-mono text-white" x-text="`${currencyCode} ${money(cashTenderedMinor(payment))}`"></strong></p>
                                                <p class="text-slate-300">Vuelto: <strong class="font-mono text-emerald-200" x-text="`${currencyCode} ${money(paymentChangeMinor(payment))}`"></strong></p>
                                            </div>
                                            <p class="mt-2 text-sm text-cyan-200">
                                                Destino: <span x-text="financialAccountLabel(payment.financial_account_id)"></span>
                                            </p>
                                            <p
                                                x-show="payment.reference"
                                                class="mt-2 text-sm text-slate-400"
                                            >
                                                Referencia: <span class="font-mono text-slate-200" x-text="payment.reference"></span>
                                            </p>
                                            <p
                                                x-show="paymentHasStructuredEvidence(payment)"
                                                class="mt-2 text-sm leading-6 text-cyan-200"
                                            >
                                                Evidencia: <span x-text="paymentEvidenceSummary(payment)"></span>
                                            </p>
                                        </div>
                                    </template>

                                    <div
                                        x-show="receivableAmountMinor() > 0"
                                        class="rounded-2xl border border-amber-400/30 bg-amber-400/10 p-4"
                                        data-sale-receivable-review
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <strong class="text-lg text-amber-100">Saldo pendiente en cuenta corriente</strong>
                                            <strong class="font-mono text-xl text-amber-100" x-text="`${currencyCode} ${money(receivableAmountMinor())}`"></strong>
                                        </div>
                                        <p class="mt-2 text-sm text-slate-300">
                                            Cliente: <span class="font-bold text-white" x-text="customerSummary()"></span>
                                        </p>
                                        <p x-show="receivableDueOn" class="mt-1 text-xs text-slate-400">
                                            Vencimiento: <span x-text="receivableDueOn"></span>
                                        </p>
                                        <p class="mt-2 text-xs text-slate-500">Este importe no se registra como pago recibido.</p>
                                    </div>
                                </div>

                                <p x-show="receivableAmountMinor() > 0" class="mt-7 text-lg font-bold text-slate-100">
                                    ¿Confirmás la venta con
                                    <span class="font-mono text-white" x-text="`${currencyCode} ${money(paymentTotalMinor())}`"></span>
                                    recibido y
                                    <span class="font-mono text-amber-200" x-text="`${currencyCode} ${money(receivableAmountMinor())}`"></span>
                                    pendiente en cuenta corriente?
                                </p>

                                <p x-show="receivableAmountMinor() <= 0 && payments.length === 1 && payments[0]?.method === 'cash'" class="mt-7 text-lg font-bold leading-8 text-slate-100">
                                    ¿Confirmás aplicar
                                    <span class="font-mono text-white" x-text="`${currencyCode} ${money(paymentAmountMinor(payments[0]?.amount))}`"></span>,
                                    recibir
                                    <span class="font-mono text-white" x-text="`${currencyCode} ${money(cashTenderedMinor(payments[0]))}`"></span>
                                    y entregar
                                    <span class="font-mono text-emerald-200" x-text="`${currencyCode} ${money(paymentChangeMinor(payments[0]))}`"></span>
                                    de vuelto?
                                </p>
                                <p x-show="receivableAmountMinor() <= 0 && payments.length === 1 && payments[0]?.method !== 'cash'" class="mt-7 text-lg font-bold text-slate-100">
                                    ¿Confirmás que recibiste
                                    <span class="font-mono text-white" x-text="`${currencyCode} ${money(saleTotalMinor())}`"></span>
                                    en
                                    <span class="text-emerald-200" x-text="paymentMethodLabel(payments[0]?.method)"></span>?
                                </p>
                                <p x-show="receivableAmountMinor() <= 0 && payments.length > 1" class="mt-7 text-lg font-bold text-slate-100">
                                    ¿Confirmás que estos son los medios e importes recibidos?
                                </p>

                                <div class="mt-8 rounded-xl border border-amber-400/25 bg-amber-400/10 px-4 py-3 text-sm font-bold text-amber-100">
                                    Enter y F7 no confirman. Esc vuelve un nivel. El cierre requiere el botón explícito.
                                </div>

                                <div class="mt-8 flex flex-wrap justify-center gap-3">
                                    <button
                                        type="button"
                                        @click="backPaymentLevel()"
                                        class="rounded-xl border border-slate-600 px-5 py-3 text-sm font-bold text-slate-200"
                                        data-sale-payment-review-back
                                    >
                                        Volver al cobro <span class="ml-1 text-[11px] font-mono text-slate-500">Esc</span>
                                    </button>
                                    <button
                                        type="submit"
                                        data-sale-final-submit
                                        @keydown.enter.prevent
                                        class="rounded-xl bg-amber-400 px-7 py-3 text-base font-black text-slate-950 transition hover:bg-amber-300"
                                    >
                                        Confirmar cobro
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
