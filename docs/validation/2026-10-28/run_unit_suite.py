from _validation_context import validation_context

SOURCE, OUTPUT = validation_context()
import pytest

raise SystemExit(pytest.main([
    str(SOURCE / 'tests' / 'unit'), '-q', '-p', 'no:cacheprovider',
    '--basetemp', str(OUTPUT / 'pytest-tmp'),
    '--junitxml', str(OUTPUT / 'unit-results.xml'),
]))
