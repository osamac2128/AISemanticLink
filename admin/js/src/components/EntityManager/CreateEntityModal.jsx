/**
 * CreateEntityModal - Modal for manually creating new entities
 */

import { useState, useCallback } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { createEntity, createMutationOptions } from '../../api/client';
import { ENTITY_TYPES } from './index';

const MAX_DESCRIPTION_LENGTH = 500;

export default function CreateEntityModal( { isOpen, onClose } ) {
	const queryClient = useQueryClient();
	const [ name, setName ] = useState( '' );
	const [ type, setType ] = useState( 'PERSON' );
	const [ description, setDescription ] = useState( '' );
	const [ aliasInput, setAliasInput ] = useState( '' );
	const [ aliases, setAliases ] = useState( [] );

	const createMutation = useMutation( {
		mutationFn: ( data ) => createEntity( data ),
		...createMutationOptions( { successMessage: 'Entity created' } ),
		onSuccess: () => {
			queryClient.invalidateQueries( { queryKey: [ 'entities' ] } );
			resetForm();
			onClose();
		},
	} );

	const resetForm = useCallback( () => {
		setName( '' );
		setType( 'PERSON' );
		setDescription( '' );
		setAliasInput( '' );
		setAliases( [] );
		createMutation.reset();
	}, [ createMutation ] );

	const handleClose = () => {
		if ( createMutation.isPending ) {
			return;
		}
		resetForm();
		onClose();
	};

	const handleAddAlias = () => {
		const trimmed = aliasInput.trim();
		if (
			trimmed &&
			! aliases.some(
				( a ) => a.toLowerCase() === trimmed.toLowerCase()
			)
		) {
			setAliases( [ ...aliases, trimmed ] );
			setAliasInput( '' );
		}
	};

	const handleRemoveAlias = ( aliasToRemove ) => {
		setAliases( aliases.filter( ( a ) => a !== aliasToRemove ) );
	};

	const handleAliasKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			handleAddAlias();
		}
	};

	const handleSubmit = ( e ) => {
		e.preventDefault();
		if ( ! name.trim() || createMutation.isPending ) {
			return;
		}

		createMutation.mutate( {
			name: name.trim(),
			type,
			description: description.trim(),
			aliases,
		} );
	};

	const isSubmitDisabled =
		! name.trim() || createMutation.isPending;

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div className="fixed inset-0 z-50 overflow-y-auto">
			<div
				className="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
				onClick={ handleClose }
			/>

			<div className="flex min-h-full items-center justify-center p-4">
				<form
					onSubmit={ handleSubmit }
					className="relative bg-white rounded-lg shadow-xl max-w-lg w-full"
				>
					<div className="px-6 py-4 border-b border-gray-200">
						<div className="flex items-center justify-between">
							<h3 className="text-lg font-semibold text-gray-900">
								Create Entity
							</h3>
							<button
								type="button"
								onClick={ handleClose }
								disabled={ createMutation.isPending }
								className="text-gray-400 hover:text-gray-500 disabled:opacity-50"
							>
								<svg
									className="w-5 h-5"
									fill="none"
									viewBox="0 0 24 24"
									stroke="currentColor"
								>
									<path
										strokeLinecap="round"
										strokeLinejoin="round"
										strokeWidth={ 2 }
										d="M6 18L18 6M6 6l12 12"
									/>
								</svg>
							</button>
						</div>
						<p className="mt-1 text-sm text-gray-500">
							Manually add a new entity to the index.
						</p>
					</div>

					<div className="px-6 py-4 space-y-4">
						{ /* Name */ }
						<div>
							<label
								htmlFor="entity-name"
								className="block text-sm font-medium text-gray-700"
							>
								Name <span className="text-red-500">*</span>
							</label>
							<input
								id="entity-name"
								type="text"
								value={ name }
								onChange={ ( e ) =>
									setName( e.target.value )
								}
								placeholder="e.g. John Smith"
								className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
								autoFocus
								required
							/>
						</div>

						{ /* Type */ }
						<div>
							<label
								htmlFor="entity-type"
								className="block text-sm font-medium text-gray-700"
							>
								Type <span className="text-red-500">*</span>
							</label>
							<select
								id="entity-type"
								value={ type }
								onChange={ ( e ) =>
									setType( e.target.value )
								}
								className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
							>
								{ ENTITY_TYPES.map( ( t ) => (
									<option
										key={ t.value }
										value={ t.value }
									>
										{ t.label }
									</option>
								) ) }
							</select>
						</div>

						{ /* Description */ }
						<div>
							<label
								htmlFor="entity-description"
								className="block text-sm font-medium text-gray-700"
							>
								Description
							</label>
							<textarea
								id="entity-description"
								value={ description }
								onChange={ ( e ) => {
									if (
										e.target.value.length <=
										MAX_DESCRIPTION_LENGTH
									) {
										setDescription( e.target.value );
									}
								} }
								placeholder="Brief description of the entity..."
								rows={ 3 }
								className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 resize-none"
							/>
							<div className="mt-1 text-xs text-gray-400 text-right">
								{ description.length }/{ MAX_DESCRIPTION_LENGTH }
							</div>
						</div>

						{ /* Aliases */ }
						<div>
							<label className="block text-sm font-medium text-gray-700">
								Aliases
							</label>
							<div className="mt-1 flex gap-2">
								<input
									type="text"
									value={ aliasInput }
									onChange={ ( e ) =>
										setAliasInput( e.target.value )
									}
									onKeyDown={ handleAliasKeyDown }
									placeholder="Add alias..."
									className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
								/>
								<button
									type="button"
									onClick={ handleAddAlias }
									disabled={ ! aliasInput.trim() }
									className="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
								>
									Add
								</button>
							</div>
							{ aliases.length > 0 && (
								<div className="mt-2 flex flex-wrap gap-1.5">
									{ aliases.map( ( alias ) => (
										<span
											key={ alias }
											className="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-700"
										>
											{ alias }
											<button
												type="button"
												onClick={ () =>
													handleRemoveAlias(
														alias
													)
												}
												className="text-blue-400 hover:text-blue-600"
											>
												<svg
													className="w-3 h-3"
													fill="none"
													viewBox="0 0 24 24"
													stroke="currentColor"
												>
													<path
														strokeLinecap="round"
														strokeLinejoin="round"
														strokeWidth={
															2
														}
														d="M6 18L18 6M6 6l12 12"
													/>
												</svg>
											</button>
										</span>
									) ) }
								</div>
							) }
						</div>

						{ /* Error display */ }
						{ createMutation.isError && (
							<div className="p-3 bg-red-50 border border-red-200 rounded-lg">
								<div className="flex items-start gap-2">
									<svg
										className="w-5 h-5 text-red-500 mt-0.5 shrink-0"
										fill="none"
										viewBox="0 0 24 24"
										stroke="currentColor"
									>
										<path
											strokeLinecap="round"
											strokeLinejoin="round"
											strokeWidth={ 2 }
											d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
										/>
									</svg>
									<p className="text-sm text-red-600">
										{ createMutation.error?.message ||
											'Failed to create entity' }
									</p>
								</div>
							</div>
						) }
					</div>

					<div className="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
						<button
							type="button"
							onClick={ handleClose }
							disabled={ createMutation.isPending }
							className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50"
						>
							Cancel
						</button>
						<button
							type="submit"
							disabled={ isSubmitDisabled }
							className="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2"
						>
							{ createMutation.isPending ? (
								<>
									<svg
										className="w-4 h-4 animate-spin"
										fill="none"
										viewBox="0 0 24 24"
									>
										<circle
											className="opacity-25"
											cx="12"
											cy="12"
											r="10"
											stroke="currentColor"
											strokeWidth="4"
										/>
										<path
											className="opacity-75"
											fill="currentColor"
											d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
										/>
									</svg>
									Creating...
								</>
							) : (
								'Create Entity'
							) }
						</button>
					</div>
				</form>
			</div>
		</div>
	);
}
