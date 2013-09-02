echo "this program will print your sels"
read -p "input your choice:" choice
case $choice in
#case $1 in
    "one")
        echo "your choice is ONE"
        ;;
    "two")
       echo "your choice is tow"
        ;;
    "three")
        echo "your choice is THree"
        ;;
    *)
        echo "usae $0 {one/two/three}"
esac
