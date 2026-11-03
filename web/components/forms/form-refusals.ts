import { useCallback } from 'react';
import { useTranslation } from '@whity/features/i18n';

/**
 * A refused answer, in the reader's language.
 *
 * The server's sentence is English, so a page in Arabic cannot show it. A refusal
 * about one answer also carries a stable `reason`, and this turns that into wording
 * the page owns. Any reason it doesn't know falls back to the server's sentence, so
 * a newer server still says something true.
 */
export interface RefusalDetails {
  reason?: string;
  field_key?: string;
  params?: Record<string, string | number>;
}

export interface RefusalBody {
  error?: string;
  details?: RefusalDetails;
}

export type RefusalMessage = (
  body: RefusalBody,
  labelOf: (fieldKey: string) => string,
  fallback: string
) => string;

/**
 * `labelOf` gives the question's label as this reader sees it, by field key.
 * `fallback` is shown when the server sent nothing usable.
 */
export function useRefusalMessage(): RefusalMessage {
  const t = useTranslation('forms');

  return useCallback<RefusalMessage>((body, labelOf, fallback) => {
    const details = body.details;
    const server = body.error ?? fallback;
    if (details?.reason === undefined) return server;

    const params = details.params ?? {};
    const label = details.field_key !== undefined ? labelOf(details.field_key) : '';
    const vars = { ...params, label };

    switch (details.reason) {
      case 'required':
        return t('refusal.required', '{label} is required.', vars);
      case 'must_be_ticked':
        return t('refusal.mustBeTicked', '{label} must be ticked.', vars);
      case 'not_text':
        return t('refusal.notText', '{label} must be text.', vars);
      case 'too_long':
        return t('refusal.tooLong', '{label} must be {limit} characters or fewer.', vars);
      case 'bad_format':
        return t('refusal.badFormat', '{label} is not in the expected format.', vars);
      case 'not_a_number':
        return t('refusal.notANumber', '{label} must be a number.', vars);
      case 'below_min':
        return t('refusal.belowMin', '{label} must be at least {min}.', vars);
      case 'above_max':
        return t('refusal.aboveMax', '{label} must be at most {max}.', vars);
      case 'not_a_date':
        return t('refusal.notADate', '{label} must be a real date.', vars);
      case 'not_a_choice':
        return t('refusal.notAChoice', '{label} must be one of the offered choices.', vars);
      case 'too_few_choices':
        return t('refusal.tooFewChoices', '{label} needs at least {min} choices.', vars);
      case 'too_many_choices':
        return t('refusal.tooManyChoices', '{label} allows at most {max} choices.', vars);
      case 'not_yes_or_no':
        return t('refusal.notYesOrNo', '{label} must be yes or no.', vars);
      case 'bad_file_reference':
      case 'file_not_found':
        return t('refusal.fileNotFound', 'We could not find the file attached to {label}. Please attach it again.', vars);
      case 'bad_reference':
        return t('refusal.badReference', '{label} must name a record in this workspace.', vars);
      case 'too_many_files':
        return t('refusal.tooManyFiles', '{label} allows at most {max} files.', vars);
      case 'bad_rows':
        return t('refusal.badRows', '{label} must be a list of rows.', vars);
      case 'too_few_rows':
        return t('refusal.tooFewRows', '{label} needs at least {min} rows.', vars);
      case 'too_many_rows':
        return t('refusal.tooManyRows', '{label} allows at most {max} rows.', vars);
      case 'bad_signature':
        return t('refusal.badSignature', '{label} must be your full name.', vars);
      case 'file_too_large':
        return t('refusal.fileTooLarge', 'That file is too large. The limit is {limit_mb} MB.', vars);
      case 'file_empty':
        return t('refusal.fileEmpty', 'That file is empty.', vars);
      case 'file_kind_refused':
        return t('refusal.fileKindRefused', 'That kind of file cannot be attached. Please upload a PDF, Word (.docx), PNG or JPEG.', vars);
      case 'file_mismatch':
        return t('refusal.fileMismatch', 'That file does not look like what it claims to be.', vars);
      default:
        return server;
    }
  }, [t]);
}
