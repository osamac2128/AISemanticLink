import { createPortal } from '@wordpress/element';

export function ConfirmDialog( {
	isOpen,
	title = 'Confirm',
	message,
	confirmLabel = 'Confirm',
	cancelLabel = 'Cancel',
	variant = 'danger',
	onConfirm,
	onCancel,
} ) {
	if ( ! isOpen ) {
		return null;
	}

	const confirmClass =
		variant === 'danger'
			? 'bg-red-600 hover:bg-red-700 text-white'
			: 'bg-blue-600 hover:bg-blue-700 text-white';

	return createPortal(
		<div
			role="dialog"
			aria-modal="true"
			aria-labelledby="confirm-dialog-title"
			className="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
			onClick={ onCancel }
			onKeyDown={ ( e ) => {
				if ( e.key === 'Escape' ) {
					onCancel();
				}
			} }
		>
			<div
				className="bg-white rounded-lg shadow-xl max-w-md w-full p-6"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h3 id="confirm-dialog-title" className="text-lg font-semibold text-gray-900 mb-2">
					{ title }
				</h3>
				<p className="text-gray-600 mb-6">{ message }</p>
				<div className="flex justify-end gap-3">
					<button
						className="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-md"
						onClick={ onCancel }
						autoFocus
					>
						{ cancelLabel }
					</button>
					<button
						className={ `px-4 py-2 text-sm font-medium rounded-md ${ confirmClass }` }
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</div>
			</div>
		</div>,
		document.body
	);
}
