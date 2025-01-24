document.addEventListener('DOMContentLoaded', function () {
    // Update available command params on load
    const commandSelect = document.getElementById('command_select');
    const selectedOption = commandSelect.selectedOptions[0];
    updateAvailableParams(selectedOption);
    commandSelect.addEventListener('change', function () {
        // Update available command params on select change
        const selectedOption = commandSelect.selectedOptions[0];
        updateAvailableParams(selectedOption);
        // Reset the command parameters input
        const commandParamsInput = document.getElementById('command_params');
        commandParamsInput.value = '';
    });
    updateSchedulingInput();
});

/**
 * Update available command params based on the selected command
 * @param command
 */
function updateAvailableParams(command) {
    const availableParamsDiv = document.getElementById('command_available_params');
    const availableArguments = document.getElementById('command_available_arguments');
    const availableOptions = document.getElementById('command_available_options');
    const paramsTranslationsDivDataset = document.getElementById('params-translations').dataset;

    // Empty the div before updating
    availableArguments.innerHTML = '';
    availableOptions.innerHTML = '';

    // Track if there are any parameters to display
    let hasParams = false;

    // Loop through all the dataset properties of the option
    for (const [key, value] of Object.entries(command.dataset)) {
        // Check which key the command starts with and display the given value
        if (key.startsWith('command_argument_name') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.argument, availableArguments);
            hasParams = true;
        }
        if (key.startsWith('command_argument_description') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.description, availableArguments);
        }
        if (key.startsWith('command_argument_mode') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.mode, availableArguments);
        }
        if (key.startsWith('command_option_name') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.option, availableOptions);
            hasParams = true;
        }
        if (key.startsWith('command_option_description') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.description, availableOptions);
        }
        if (key.startsWith('command_option_mode') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.mode, availableOptions);
        }
        if (key.startsWith('command_option_shortcut') && value) {
            addCommandParamsListItem(value, paramsTranslationsDivDataset.shortcut, availableOptions);
        }
    }

    // Show or hide the available parameters div based on whether params exist
    availableParamsDiv.style.display = hasParams ? 'block' : 'none';
    attachCommandParamsListeners();
}

/**
 * Add list items to the available command argument/options
 * @param value
 * @param text
 * @param elementToAppend
 */
function addCommandParamsListItem(value, text, elementToAppend) {
    const paramsTranslationsDivDataset = document.getElementById('params-translations').dataset;
    const isArgument = text === paramsTranslationsDivDataset.argument;
    const isOption = text === paramsTranslationsDivDataset.option;
    const isMode = text === paramsTranslationsDivDataset.mode;
    const isName = isArgument || isOption;
    // Create the li element and add the text with value into it
    const paramElementLi = document.createElement('li');
    let listText = (isName ? `<strong>${value}: </strong>` : `<em>${text}: </em>` + value)
    if (isName) {
        // Make clickable and focus to the command parameters input
        listText = '<a class="js-command-add-params" href="#command_params" ' +
            'data-value="' + (!isArgument ? value : null) + '" ' +
            'style="text-decoration: none; color: black;">' + listText + '</a>';
    }
    paramElementLi.classList.add('my-1');
    if (isMode) {
        paramElementLi.classList.add('js-mode');
    }
    paramElementLi.innerHTML = listText;
    if (!isName) {
        // If name is false we want to create a sub ul element under the argument/option name
        const paramElementUl = document.createElement('ul');
        paramElementUl.appendChild(paramElementLi);
        elementToAppend.appendChild(paramElementUl);
    } else {
        elementToAppend.appendChild(paramElementLi);
    }
}

// Find the closest `.js-mode` element by traversing siblings
function findJsModeElement(startElement) {
    let currentElement = startElement;
    while (currentElement) {
        if (currentElement.querySelector('.js-mode')) {
            return currentElement.querySelector('.js-mode');
        }
        currentElement = currentElement.nextElementSibling;
    }
    return null;
}

function attachCommandParamsListeners() {
    const commandParamsInput = document.getElementById('command_params');
    document.querySelectorAll('.js-command-add-params').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!this.dataset.value || this.dataset.value === 'null') return;
            if (commandParamsInput.value.includes(this.dataset.value)) return;

            // Start from the closest 'li' and traverse to find the `.js-mode`
            const nextJsMode = findJsModeElement(this.closest('li').nextElementSibling);
            const isModeValueNone = nextJsMode && nextJsMode.innerText.trim().includes('VALUE_NONE');

            // Construct the command param
            const prefix = '--';
            const suffix = isModeValueNone ? '' : '=';

            // Append to the input with correct formatting
            commandParamsInput.value = `${commandParamsInput.value.trim()} ${prefix}${this.dataset.value}${suffix}`.trim();
        });
    });
}

/**
 * Shows/hides the input for recurring schedule message
 */
function updateSchedulingInput() {
    const commandMethodSelect = document.getElementById('command_method');
    const selectedMethod = commandMethodSelect.selectedOptions[0];
    const cronInputDiv = document.getElementById('command_cron_div');
    cronInputDiv.style.display = selectedMethod.value === 'recurring' ? 'block' : 'none';
    commandMethodSelect.addEventListener('change', function () {
        const selectedMethod = commandMethodSelect.selectedOptions[0];
        cronInputDiv.style.display = selectedMethod.value === 'recurring' ? 'block' : 'none';
        document.getElementById('command_cron').required = selectedMethod.value === 'recurring';
    });
}
