# Archiver - erpo2sharefile2edoc

This archiver finds and archives all files in a specified root folder.

## Annotated example

```yaml
type: erpo2sharefile2edoc

notifications:
 email:
  from: sharefile2edoc@example.com
  to: test@example.com

edoc:
 ws_url: 'http://…/edocdotnetapi/edoc.asmx' # eDoc api WSDL url.
 ws_username: '' # eDoc api username
 ws_password: '' # eDoc api password
 user_identifier: 'www.fujitsu.dk/esdh/bruger/…' # eDoc user identifier
 admin_url: 'http://edoc:8080' # Admin url

 # getItemList Project
 # Id of project under which to create new eDoc case files.
 project_id: '

 case_file:
  # Case file name TWIG template. `item` is a an instance of `App\ShareFile\Item`.
  name: '{{ item.name }} – {{ item.sagstitel|default(false) }}'
  # Default (and required) values
  defaults:
   # getItemList CaseType
   CaseFileTypeCode: ''
   # getItemList CaseWorker
   CaseFileManagerReference: ''
   HasPersonrelatedInfo: 300002 # No; use 300001 for Yes.
   # getItemList HandlingCodeTree
   HandlingCodeId: ''
   # getItemList PrimaryCodeTree
   PrimaryCode: ''
   # getItemList PublicAccessCode
   PublicAccess: 4 # "Ingen"

  # Webhook configuration
  # The payload sent is a JSON object with two keys `esdh` and `edoc_case_file`;
  # see # `edoc_case_file.json` for an example. The value of `esdh` if the same
  # as `edoc_case_file.SequenceNumber`.
  webhook:
   # Url TWIG template. `item` is a an instance of `App\ShareFile\Item`.
   url: 'https://matrikulaersagapi.azurewebsites.net/api/sag/{{ item.metadata.sagsnummer|default("") }}'
   method: POST
   # Options for the Guzzle http client
   guzzle_options:
    # @see https://docs.guzzlephp.org/en/stable/request-options.html#auth
    auth:
     - 'c7jQpHPhzcpaM6PxYab8J6C6AgPBScGYJoaM5TS8' # username
     - 'xBXB84s88aodxMhC843YCjaKAtEDFDh3RfScknoq' # password

 document:
  # Document name TWIG template. `item` is a an instance of `App\ShareFile\Item`.
  name: '{{ item.name }}'
  defaults:
   # getItemList DocumentType
   DocumentTypeReference: '' # Notat
   DocumentCategoryCode: ''
   # getItemList CaseWorker
   CaseManagerReference: '' # "adm\svcedocarkmtm"
   # getItemList PublicAccessCode
   PublicAccess: 4 # "Ingen"

sharefile:
 hostname: '' # ShareFile hostname, e.g. aarhus.sharefile.com

 client_id: '' # ShareFile client id
 secret: '' # ShareFile secret
 username: '' # ShareFile username
 password: '' # ShareFile password

 root_id: '' # The ShareFile root folder id
 ```
