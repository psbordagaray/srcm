<?php
    $editing = isset($technicalModel);
?>

<div class="space-y-6">

    <div>
        <label for="brand_id" class="mb-2 block text-sm font-semibold text-slate-200">
            Marca
        </label>

        <select
            id="brand_id"
            name="brand_id"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option value="">Seleccionar marca</option>

            <?php $__currentLoopData = $brands; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $brand): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option
                    value="<?php echo e($brand->id); ?>"
                    <?php if((string) old('brand_id', $technicalModel->brand_id ?? '') === (string) $brand->id): echo 'selected'; endif; ?>
                >
                    <?php echo e($brand->name); ?>

                    <?php echo e($brand->active ? '' : '(inactiva)'); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>

        <?php $__errorArgs = ['brand_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div>
        <label for="product_category_id" class="mb-2 block text-sm font-semibold text-slate-200">
            Categoría
        </label>

        <select
            id="product_category_id"
            name="product_category_id"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option value="">Seleccionar categoría</option>

            <?php $__currentLoopData = $categories; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $category): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <option
                    value="<?php echo e($category->id); ?>"
                    <?php if((string) old('product_category_id', $technicalModel->product_category_id ?? '') === (string) $category->id): echo 'selected'; endif; ?>
                >
                    <?php echo e($category->name); ?>

                    <?php echo e($category->active ? '' : '(inactiva)'); ?>

                </option>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select>

        <?php $__errorArgs = ['product_category_id'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div>
        <label for="code" class="mb-2 block text-sm font-semibold text-slate-200">
            Código técnico
        </label>

        <input
            id="code"
            name="code"
            type="text"
            required
            maxlength="100"
            value="<?php echo e(old('code', $technicalModel->code ?? '')); ?>"
            placeholder="Ej.: 43LM6300, SM-A325F"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <p class="mt-2 text-xs text-slate-500">
            Es el identificador técnico principal del modelo.
        </p>

        <?php $__errorArgs = ['code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div>
        <label for="name" class="mb-2 block text-sm font-semibold text-slate-200">
            Nombre comercial
            <span class="font-normal text-slate-500">(opcional)</span>
        </label>

        <input
            id="name"
            name="name"
            type="text"
            maxlength="255"
            value="<?php echo e(old('name', $technicalModel->name ?? '')); ?>"
            placeholder="Ej.: Galaxy A32"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
        >

        <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div>
        <label for="description" class="mb-2 block text-sm font-semibold text-slate-200">
            Descripción
            <span class="font-normal text-slate-500">(opcional)</span>
        </label>

        <textarea
            id="description"
            name="description"
            rows="4"
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white placeholder:text-slate-500 focus:border-cyan-400 focus:ring-cyan-400"
            placeholder="Información general del modelo..."
        ><?php echo e(old('description', $technicalModel->description ?? '')); ?></textarea>

        <?php $__errorArgs = ['description'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div>
        <label for="active" class="mb-2 block text-sm font-semibold text-slate-200">
            Estado
        </label>

        <select
            id="active"
            name="active"
            required
            class="w-full rounded-xl border-slate-700 bg-slate-950 text-white focus:border-cyan-400 focus:ring-cyan-400"
        >
            <option
                value="1"
                <?php if((string) old('active', isset($technicalModel) ? (int) $technicalModel->active : 1) === '1'): echo 'selected'; endif; ?>
            >
                Activo
            </option>

            <option
                value="0"
                <?php if((string) old('active', isset($technicalModel) ? (int) $technicalModel->active : 1) === '0'): echo 'selected'; endif; ?>
            >
                Inactivo
            </option>
        </select>

        <?php $__errorArgs = ['active'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
            <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
        <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
    </div>

    <div class="flex flex-col-reverse gap-3 border-t border-slate-800 pt-6 sm:flex-row sm:justify-end">
        <a
            href="<?php echo e(route('technical-models.index')); ?>"
            class="inline-flex items-center justify-center rounded-xl border border-slate-700 px-4 py-2.5 text-sm font-semibold text-slate-300 transition hover:border-slate-500 hover:text-white"
        >
            Cancelar
        </a>

        <button
            type="submit"
            class="inline-flex items-center justify-center rounded-xl bg-cyan-400 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:bg-cyan-300"
        >
            <?php echo e($editing ? 'Guardar cambios' : 'Crear modelo técnico'); ?>

        </button>
    </div>

</div>
<?php /**PATH C:\laragon\www\srcm\resources\views/technical-models/_form.blade.php ENDPATH**/ ?>