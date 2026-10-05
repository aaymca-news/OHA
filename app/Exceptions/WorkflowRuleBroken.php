<?php

namespace App\Exceptions;

use DomainException;

/**
 * An action was refused because it would break a workflow rule. The message is
 * written for the person who tried it, and says what to do instead.
 */
final class WorkflowRuleBroken extends DomainException {}
