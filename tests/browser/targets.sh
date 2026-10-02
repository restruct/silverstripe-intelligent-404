# Browser-test targets for this module, sourced by the shared runner
# (~/Sites/0_ss-mods-maintenance/tools/browser/run.sh) and by .github/workflows/browser-tests.yml.
# Plain bash assignments only. CI tests only the targets with an empty SS<n>_SRC_REF (= this
# checkout). main serves both majors.

BROWSER_PACKAGE="restruct/silverstripe-intelligent-404"
BROWSER_TARGETS="ss5 ss6"

SS5_RECIPE="^5"
SS5_PHP="8.3"
SS5_PORT="8885"
SS5_SRC_REF=""

SS6_RECIPE="^6"
SS6_PHP="8.3"
SS6_PORT="8886"
SS6_SRC_REF=""

# The scratch hosts run in dev mode, where the module is off unless allow_in_dev_mode is set:
# fixtures/_config/intelligent404.yml does that.
